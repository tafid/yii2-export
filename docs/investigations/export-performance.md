# Export pipeline performance investigation

- **Date:** 2026-08-06
- **Consuming application commit (hipanel.advancedhosters.com):** `f25e4716d559e0d7454126e89937184c912428a5`
- **This package commit at time of investigation:** `5ee81e737163c06b82bfe54aa58c91b913f56eef` (branch `master`, matches the `dev-master` reference pinned in the app's `composer.lock`)
- **Scope:** full backend request path for CSV/XLSX/TSV/MD export triggered from a hipanel grid (`start-export` → `progress-export` (SSE) → `download-export`), performance hotspots, testability, and a benchmark strategy.
- **Audience:** developers working on this package, and AI agents picking up follow-up work — read this before re-deriving the call graph from scratch.

This document intentionally separates **facts verified by reading the source** from **interpretation** and from **open questions**. Treat anything outside "Confirmed facts" as a hypothesis to re-check, not a given.

**Status update (same branch, `export-performance-refactor`, commits after this one):** the decoupling and improvements described as a *plan* in §2/§3/§5 below have since been implemented — `ExportRequest`/`ExportRequestFactory` now decouple `Exporter::runJob()` from `StartExportAction`/`Controller`; `AbstractExporter::generateBody()` streams rows via a `Generator` instead of buffering the whole export; `ExportJob::commitThrottled()` replaces the per-row unconditional cache commit; `SaveManager` writes a real file extension and streams downloads via `fopen()` instead of base64. PHPUnit coverage exists under `tests/unit` and `tests/smoke` (run via `vendor/bin/phpunit -c vendor/hiqdev/yii2-export/phpunit.xml.dist` from the app root). §1.7's "no tests exist" and §5's "technically reachable... after decoupling" should be read as the *pre-refactor* baseline, not the current state. The one gap called out below (§4, HiAPI-backed `generateBody()` integration coverage) is still open.

---

## 1. Confirmed facts

### 1.1 Routing (in the consuming application, not this package)

The four export actions are wired centrally, not per-controller:

- `hipanel\base\Controller::actions()` — `vendor/hiqdev/hipanel-core/src/base/Controller.php:61-83` — registers:
  - `start-export` → `hiqdev\yii2\export\actions\StartExportAction`
  - `progress-export` → `hiqdev\yii2\export\actions\ProgressExportAction`
  - `download-export` → `hiqdev\yii2\export\actions\DownloadExportAction`
  - `cancel-export` → `hiqdev\yii2\export\actions\CancelExportAction`
- `hipanel\base\CrudController` (`hipanel-core/src/base/CrudController.php:16`) does not override `actions()`.
- Example concrete controller: `hipanel\modules\server\controllers\HubController` (`vendor/hiqdev/hipanel-module-server/src/controllers/HubController.php:43,74-76`) — `actions()` does `array_merge(parent::actions(), [...])`, inheriting the four export actions unchanged. This is why `server/hub/start-export` etc. exist without any export-specific code in `HubController` itself.
- This package's `config/common.php:12-16` is auto-merged into the app config via the `hiqdev/yii2-composer-config` plugin (`extra.config-plugin.common` in this package's `composer.json`). It registers `controllerMap['exporter']` (a console command, see 1.6), the `exporter` app component (`Exporter::class`), and the `ExporterFactoryInterface` DI binding.

### 1.2 Request lifecycle: `start-export`

`StartExportAction::run()` — `src/actions/StartExportAction.php:15-30`:
- Only proceeds if `$this->controller->request->isAjax`.
- Wraps the real work in `hipanel\actions\RunProcessAction('start-export', $this->controller)` (line 18), whose `onRunProcess` closure (lines 19-26) reads `export_id` from POST, validates it as a 10-digit numeric string (else throws `BadRequestHttpException`), resolves the current grid "representation" columns, and calls `Yii::$app->exporter->runJob($id, $this, $representation->getColumns())`.
- `RunProcessAction::run()` (`vendor/hiqdev/hipanel-core/src/actions/RunProcessAction.php:16-40`): sets `ignore_user_abort(true)`, raises `memory_limit` to `10G`, flushes output buffers, calls **`fastcgi_finish_request()`** (line 30), then runs `onRunProcess` and `die()`.
- **There is no queue (no yii2-queue, no job dispatch to another worker).** `fastcgi_finish_request()` returns the HTTP response to the browser immediately while the *same* PHP-FPM worker process keeps executing in the background. Generation is synchronous and single-process, just detached from the client TCP connection.
- After firing this, `StartExportAction::run()` calls `Yii::$app->end()`.

### 1.3 Export core: `Exporter::runJob()` and `AbstractExporter`

`hiqdev\yii2\export\components\Exporter::runJob(string $id, StartExportAction $action, array $representationColumns): void` — `src/components/Exporter.php:30-60`:
1. `ExportJob::findOrCreate($id)` (cache lookup).
2. `prepareExporter($action, $columns)` (line 73) → `initializeExporter()` (line 106):
   - `getExportFormat()` reads `$action->controller->request->get('format')` (line 118-121).
   - `createExporter()` → `ExporterFactory::build($type)` (`src/exporters/ExporterFactory.php:19`), DI-resolved per `config/common.php` mapping (`csv`→`CSVExporter`, `tsv`→`TSVExporter`, `xlsx`→`XLSXExporter`, `md`→`MDExporter`).
   - `wrapDataProvider()` → `getDataProvider()` (lines 146-162): either uses the controller's `index` action config (`$action->controller->actions()['index']`) to build a fresh `IndexAction` and call `getDataProvider()` on it, or falls back to `$action->getDataProvider()`.
   - `guessGridClassName(Controller $controller)` (lines 88-104): builds `\<controller-namespace>\grid\<ControllerName>GridView` by string manipulation of the controller's class name/namespace, and `throw new RuntimeException("ExportAction cannot find a $gridClassName")` if that class doesn't exist. **This has been observed firing in production** (`runtime/logs/app.log.3`: `ExportAction cannot find a \hipanel\modules\stock\ModelGroupController\grid\Model-groupGridView`).
3. `exporter->initExportOptions()` (`AbstractExporter.php:73-97`) builds a real `yii\grid\GridView`.
4. `ExportJob::begin()` (status → RUNNING, cache write).
5. `exporter->export($job)` → `exportToFile($job->getSaver()->getFilePath())` (`AbstractExporter.php:176,126`).
6. `ExportJob::end()` (status → SUCCESS/ERROR, cache write).

**Coupling:** `runJob()` is typed to `StartExportAction $action`, and `guessGridClassName()`/`getDataProvider()`/`getExportFormat()` all reach into `$action->controller` (a live web controller instance) and `$action->controller->request`. There is no way today to exercise this code path without a real `Controller`+`Request` object graph — see §4 (testability).

### 1.4 Row generation, pagination, data source

`AbstractExporter::exportToFile()` (`src/exporters/AbstractExporter.php:126-166`):
- Calls `getExportSections()` (line 106) → `generateHeader()` (187-208), `generateBody()` (226-273), `generateFooter()` (331-348).
- **All rows from all sections are accumulated into one PHP array `$rows` of `OpenSpout\Common\Entity\Row` objects before a single `$writer->addRows($rows)` call (line 161).** Not a streaming write.

`generateBody()` (lines 226-273) — data source is a **remote REST API, not local ActiveRecord/DB**:
- Uses `hiqdev\hiart\ActiveDataProvider`.
- `$batchSize = 500` — hardcoded default at `AbstractExporter.php:34`. Verified by grep across the package: **the `per-page` HTTP request parameter is never read anywhere in this package.** Pagination size is fixed regardless of what the client sends.
- `$totalCount = $dp->getTotalCount()`, `$pageCount = ceil($totalCount / $batchSize)`; builds one `hiqdev\hiart\guzzle\Request` per page via `composeRequest()` (lines 275-283).
- Requests are `array_chunk()`'d by 5 (line 251) and sent through `hiqdev\hipanel-hiart\src\hiapi\Connection::sendPool()` (`Connection.php:96-121`) — a Guzzle `Pool` with `concurrency = 5` (the outer chunk-by-5 is redundant with the pool's own concurrency cap, but not harmful).
- After each chunk of requests completes: `ExportJob::increaseProgress()->commit()` (line 257).
- For each returned model, per row: `compileRow()` (285-302) → `getColumnValue()` (304-324, via `Column::getDataCellValue()` + `Yii::$app->formatter->format()`, or `Column::renderDataCell()` fallback) → `sanitizeRow()` (350-360, strips tags/`&nbsp;`, collapses whitespace, strips leading `=`/`+` as a CSV-injection guard) → `ExportJob::increaseProgress()->commit()` (line 267) — **one commit per row**.
- HiAPI calls are already wrapped in `Yii::beginProfile()`/`endProfile()` inside `Connection.php:82-90,105,114` — this is an existing, code-confirmed instrumentation point for an `api_fetch_seconds` metric, usable via `Yii::getLogger()->getProfiling()` without modifying any code.

### 1.5 Progress state and SSE

- `ExportJob` (`src/models/ExportJob.php`) is a plain `yii\base\Model`, not persisted to any DB table. Fields: `runTs`, `createTs`, `finishTs`, `errorMessage`, `progress`, `total`, `taskName`, `unit`, `mimeType`, `extension`, `status` (`ExportStatus` enum: `NEW`, `RUNNING`, `SUCCESS`, `ERROR`, `CANCEL`).
- Persistence is exclusively `Yii::$app->cache`, via `ExportJobStorage` (`src/helpers/ExportJobStorage.php`), key `['export-job', $id]`, TTL `3600*4` (4 hours). Whichever cache component the host app configures — not specified in this package.
- `commit()` (`ExportJob.php:189-194`) → `$storage->save()` → one `cache->set()` call. This is called once per HiAPI request-chunk **and once per exported row** (see §1.4) — the finest-grained, most frequent cache write in the whole pipeline.
- `ProgressExportAction::init()` (`src/actions/ProgressExportAction.php:14-30`) sets an `onProgress` closure used by the parent `hipanel\actions\ProgressAction::run()` (`hipanel-core/src/actions/ProgressAction.php:18-43`): sends SSE headers (`Content-Type: text/event-stream`, `Cache-Control: no-store`, `X-Accel-Buffering: no`), then loops: reads `ExportJob::findOrCreate($id)` (a cache read), emits its `toArray()` as an SSE `data:` frame, `sleep(1)`, repeat, until `needToTerminate()` is true, the client disconnects, or 60 unchanged polls elapse (`INIT_TRIES = 60`).
- **Progress percentage is computed client-side**, not server-side (`assets/js/exporter.js:116`: `Math.floor((data.progress / data.total) * 100) + '%'`). The server only ever emits raw integers.

### 1.6 File writing and download

- Serialization library: `openspout/openspout: ~4.32` (declared in this package's `composer.json:50`). **Not** `phpoffice/phpspreadsheet`, **not** `league/csv`.
  - `CSVExporter::getWriter()` → `OpenSpout\Writer\CSV\Writer`.
  - `TSVExporter` extends `CSVExporter`, overrides the field delimiter to `"\t"`.
  - `XLSXExporter::getWriter()` → `OpenSpout\Writer\XLSX\Writer`, plus `mergeColspanColumns()` in `beforeClose()` (`XLSXExporter.php:48-73`) for grid columns that span multiple cells.
  - `MDExporter` bypasses OpenSpout entirely, hand-builds a markdown table string, and writes via `SaveManager::save()` (`file_put_contents` with `LOCK_EX`) instead of `exportToFile()`.
- `SaveManager` (`src/helpers/SaveManager.php`): fixed directory `@runtime/export-reports`; `getFilePath()` (lines 56-59) returns `<dir>/<job-id>` — **no file extension on the actual on-disk file** (the extension only appears in the download filename via `getFilename()`, lines 51-54).
- `getStream()` (lines 41-44): `fopen('data://' . $job->mimeType . ';base64,' . base64_encode($this->getContent()), 'rb')` — **reads the entire file into memory via `file_get_contents()`, base64-encodes it (~+33% size), then opens a `data://` stream**, instead of `fopen()`-ing the real file path directly.
- `DownloadExportAction::run()` (`src/actions/DownloadExportAction.php:14-26`): re-fetches the job from cache, if `isSuccess()` streams via `getStream()`/`getFilename()` through `$this->controller->response->sendStreamAsFile()`, then calls `ExportJob::delete()` (removes both the cache entry and the on-disk file via `SaveManager::delete()`), else `Yii::$app->end()`.
- `CancelExportAction::run()` (`src/actions/CancelExportAction.php:11-17`): reads `id` from POST, `ExportJob::delete()`.

### 1.7 Existing tests and tooling

- **No `tests/` directory exists anywhere in this package** (verified by full file listing). No unit tests of any kind ship with `hiqdev/yii2-export` as of this commit.
- In the consuming application: only UI-level tests exist, none measuring time:
  - Codeception acceptance: `vendor/hiqdev/hipanel-module-client/tests/acceptance/seller/ClientExportCest.php` (asserts the export bulk option is visible).
  - Playwright e2e (`*/export.spec.ts`, ~10 files across `hipanel-module-server`, `hipanel-module-finance`, `hipanel-module-client`, `hipanel-module-stock`), e.g. `vendor/hiqdev/hipanel-module-server/tests/playwright/e2e/reseller/hub/export.spec.ts` — navigates to `server/hub/index` and calls `indexPage.testExport()`. UI-flow assertions only.
- Composer: `require: {ext-intl, openspout/openspout: ~4.32}`; `require-dev: {hiqdev/hidev-php: <2.0, hiqdev/hidev-hiqdev: <2.0}` — **no PHPUnit or any test framework declared in this package's own `composer.json`.**
- `vendor/bin/phpunit` **is** available at the consuming application's vendor root (pulled in by the app's own dependencies), and this package's PSR-4 namespace (`hiqdev\yii2\export\`) is already part of the app's unified Composer autoloader — so tests for this package's source can run via `vendor/bin/phpunit -c vendor/hiqdev/yii2-export/phpunit.xml.dist` from the app root without adding a nested `vendor/` inside the package.
- `src/commands/ExporterController.php` (registered as the `exporter` console command in `config/common.php`) has a single action, `actionStart()` — writes a timestamp to `@runtime/exporter.txt` and prints "Done!". **Unrelated to the actual export pipeline** — not a usable CLI entry point for benchmarking.

### 1.8 Repository/versioning structure (relevant to where changes should land)

- This package is installed by the consuming application as `dev-master` via a **git source checkout**, not a downloaded archive (the app's `composer.json` sets `extra.composer-patches.disable-downloaders = [\cweagans\Composer\Downloader\ComposerDownloader]`). Consequence: `vendor/hiqdev/yii2-export/` is its **own independent git repository**, even though the consuming app's own `.gitignore` excludes `vendor/` wholesale.
- `git remote -v` inside this checkout: `origin`/`composer` → `https://github.com/hiqdev/yii2-export.git`; `fork` → `git@github.com:tafid/yii2-export.git`.
- `git log` shows an established branch → fork → PR workflow (e.g. `HP-2913`, `HP-2763`, `HP-2720`, `HP-2352`, merged into `master` via GitHub PRs).
- This package's `composer.json` lists `Andrey Klochok <andreyklochok@gmail.com>` as "Lead frontend developer" among its authors — i.e. an existing maintainer of this exact package.

---

## 2. Interpretations and conclusions (derived from §1, not literal code)

- **Async is an illusion from the client's perspective only.** `fastcgi_finish_request()` makes `start-export` *look* non-blocking to the browser, but server-side there is exactly one PHP process doing the work, synchronously, for as long as the export takes (bounded only by the raised `10G` memory limit and whatever `max_execution_time` the CLI/FPM pool allows for a request that has already "finished"). Any load-testing or capacity reasoning about this endpoint must treat it as consuming a full PHP-FPM worker for the entire export duration, not just for the initial request handling.
- **The per-row cache write (§1.4, §1.5) is the highest-frequency operation in the pipeline** — for an export of N rows, this is N `cache->set()` calls, each round-tripping to whatever cache backend is configured. Since the SSE consumer (§1.5) only polls once per second, any commit granularity finer than roughly "once per second of row-processing work" is pure overhead with no observable UX benefit.
- **The full in-memory row buffering (§1.4) and per-row cache commits together mean peak memory and I/O both scale linearly with export size**, with no mechanism to bound either — large exports (tens of thousands of rows) are the primary risk case, not small ones.
- **The `getStream()` base64/`data://` pattern (§1.6) only matters on the *download* leg**, not generation — it inflates memory and CPU cost specifically during `download-export`, independent of how fast `progress-export` finished. A benchmark measuring `export_generation_seconds` alone will not see this cost; it needs a separate `download_seconds` measurement to surface it.
- **The `guessGridClassName()` reflection-based lookup (§1.3) is a latent correctness risk, not just a performance one** — it can throw at runtime for controllers whose grid class doesn't follow the exact naming convention, and this has already happened in production logs for `stock/model-group`. This is orthogonal to performance but worth fixing opportunistically since the same refactor that improves testability (§4) touches this method.
- **Testability is currently blocked by design, not by missing test files.** The core (`Exporter`, `AbstractExporter` and subclasses) cannot be exercised without a live `Controller`/`Request`/HiAPI graph because `runJob()`'s only entry point is typed to `StartExportAction`. Decoupling this (moving controller/action-specific resolution into a factory that produces a plain `ExportRequest` value object) is a prerequisite for any unit or smoke test, not an optional nicety.
- **The right place for source changes is this package's own git repository** (§1.8), on a branch following the project's existing `HP-XXXX`-style fork/PR convention — not ad-hoc edits to the app's gitignored `vendor/` tree, which would be indistinguishable from a scratch edit and unrecoverable after `composer install`/`update`.

---

## 3. Suspected bottlenecks (confirmed as code patterns; magnitude not independently measured yet)

1. **Per-row cache commit** — `AbstractExporter.php:267` → `ExportJob.php:189-194` → `ExportJobStorage::save()`. One cache round-trip per exported row. Magnitude depends entirely on the cache backend (file/APCu/Redis/Memcached) configured by the host app — **not determined by this package**, and not confirmed here (see open questions).
2. **Paginated REST fetch loop** — `AbstractExporter.php:244-249`, `ceil(total/500)` HTTP requests to HiAPI, 5 concurrent at a time via `Connection.php:96`. For large datasets this is many sequential pool round-trips; already profiled via `Yii::beginProfile`/`endProfile`, so its real-world share of total time is directly measurable without new instrumentation.
3. **Full in-memory row buffering before a single `addRows()` call** — `AbstractExporter.php:132-161`. Memory/GC cost scaling linearly with row count, on top of whatever `openspout/openspout`'s own writer implementation costs internally (not read as part of this investigation — flagged, not asserted).
4. **`SaveManager::getStream()` base64 read-whole-file** — `SaveManager.php:41-44`. Affects `download-export` specifically, not generation.
5. **CSV vs. XLSX cost difference**, if any beyond `mergeColspanColumns()` (cheap, one pass over grid columns), would live inside `openspout/openspout` itself — **out of scope for this package, not verified.**

---

## 4. Open questions (not resolved by reading this package alone)

- Which cache component (`Yii::$app->cache`) does the consuming app configure in production, and what is its latency profile? This directly determines how expensive bottleneck #1 above actually is. Not defined in this package; would need to be checked in the app's `config/web.php`/environment-specific config.
- Is there a dedicated `config/console.php` for the consuming app, or is the console app config assembled purely through `hiqdev/yii2-composer-config`'s `extra.config-plugin.console` entries across packages? Not confirmed — relevant if a CLI/console benchmark entry point is later added at the app level.
- What, if anything, inside `openspout/openspout` itself differs in cost between CSV and XLSX writers? Not read as part of this investigation.
- Real-world row-count distribution for exports in production (how large do exports actually get?) — needed to pick realistic benchmark scenario sizes; not derivable from source alone.

---

## 5. Benchmark strategy considered

Two approaches were evaluated for measuring `export_generation_seconds` reproducibbly:

- **A — End-to-end over HTTP** (`start-export` → poll `progress-export` SSE until `status=success` → `download-export`): matches exactly what a real user experiences, including the `fastcgi_finish_request()` behavior and whatever cache backend is configured, but timing precision is limited to the SSE's 1-second poll interval (§1.5), and it exercises CSRF/session/cookie machinery that has nothing to do with the export core itself.
- **B — Direct call into the export core**, bypassing HTTP/SSE/CSRF/cookies entirely: shown to be *technically* reachable (`Exporter::runJob()` runs synchronously in-process, §1.2) but only *after* decoupling it from `StartExportAction`/`Controller` (§2, §4 in the original planning discussion) — the DTO/factory split described above.

**Decision:** work proceeds core-first — decouple the export core for testability (making variant B natural), add PHPUnit unit/smoke coverage, then build a PHP benchmark script (living in the consuming application, not this package, since it needs to target multiple modules/controllers) that calls the decoupled core directly and prints a single `export_generation_seconds` number to stdout, matching `autoresearch-cli`'s documented contract (`eval_command` output = bare numeric metric on stdout, no JSON/env vars/files required).

---

## 6. When to update this document

Re-verify and update this document when any of the following change:
- The signature or responsibilities of `Exporter::runJob()` (e.g. after the `ExportRequest`/`ExportRequestFactory` refactor lands — update §1.3 and §2 to describe the new, decoupled shape).
- The batching strategy (`$batchSize`, chunk-by-5 pooling) in `AbstractExporter`/`Connection`.
- The cache-commit granularity in `ExportJob`/`AbstractExporter` (e.g. once throttling is implemented — update §1.5 and §3.1).
- The `SaveManager` file-path/extension or streaming strategy.
- Any new queue/async dispatch mechanism is introduced (would invalidate §1.2's "synchronous, single-process" finding).
- This package's pinned commit changes materially from `5ee81e7` and a re-read of the diff suggests the call graph above no longer matches.
