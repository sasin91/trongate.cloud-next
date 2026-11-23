# Module boundaries

Records owns the shared JSON records table. Its internal `_get`, `_find`, `_all`,
`_insert`, `_put` and `_delete` actions delegate persistence to its model. Use
Modules::run() for independent calls, or the native module loader when records
must share a transaction with job or credential writes. Feature modules own
validation and workflows. Keep JSON encoding helpers private to their owning
classes; do not register global serialization helpers.

Proxmox is the sole provisioning platform. Keep application deployment and
server provisioning distinct; do not build a provider framework for hypothetical
future integrations.

The top-level Proxmox module owns provisioning and enrollment orchestration. Its
model owns persistence. Internal actions have individual block_url guards;
the constructor does not block future authenticated browser actions.

Provision creates Proxmox_client and Proxmox_ssh directly and passes them to
helpers. There is no configuration step or cloned controller. Controllers use
the native lazy model property and load other modules through Trongate.
The loader shares database transactions across those modules.

modules/jobs/worker.php starts the worker directly, with optional --once. modules/setup/init.php initializes the installation, modules/setup/migrate.php applies migrations, and modules/monitoring/collect.php collects metrics. Services invoke the scripts directly with PHP; there is no separate bin/tc wrapper.
Product operations run through the panel. The VM integration suite has its own
test-only driver for calling the same operations without a browser.

HTTP transport is shared through the standalone `engine/Http_client.php` class.
It owns TLS, connection deadlines and raw responses; modules own authentication,
request encoding and API-specific errors. The client does not retry mutations.

Controllers access the shared HTTP client through lazy `$this->http`, provided
by `Trongate::__get` like `$this->model`. Test doubles implement `Http_client`;
provider controllers must not double as their own transport.

Proxmox owns a plain `Proxmox_client` class alongside its controller and model.
The client wraps HTTPS transport with endpoint/token validation, Proxmox headers,
form encoding, API envelope decoding and task-ID validation. Configure it once,
then call `get($path)`, `post($path, $body)` or `put($path, $body)`. Credentials
are not repeated at call sites. It does not retry ambiguous mutations.

`Proxmox_ssh` owns Ed25519 keys, cloud-init SSH configuration, guest-agent
identity discovery and pinned SSH readiness checks. It uses `Proxmox_client`
for API access and the shared `Ssh` transport for host inspection. It returns
verified server data; the controller decides enrollment and the model saves it.

Jobs owns queue persistence and the worker lifecycle. Worker dispatch uses Modules::run() with a Job_context; it does not pass database connections, HTTP clients or SSH clients across that boundary. Apps, Servers, Deployments and Proxmox own feature commands and execution. Shared startup lives in root bootstrap.php; subprocess execution lives in engine/Process.php.
