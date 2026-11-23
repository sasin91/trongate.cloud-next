# Proxmox VM provisioning

Create NixOS VMs through Servers → Create on Proxmox. The installation supplies
the connection and template; the panel queues a full clone, configures SSH,
starts it, and verifies readiness before adding it to the server inventory.
There is no manual SSH connection step for these VMs.

Installation setup supplies the connection and encrypted API credential. The Proxmox module owns discovery and provisioning; there is no generic command console.
The token value uses `USER@REALM!TOKENID=SECRET` format. Both the user and token must have
permission to inspect the intended nodes and VMs. Catalogs are permission-filtered;
an empty result does not prove the cluster is empty or grant provisioning access.

Pass this non-secret payload to the internal Proxmox _discover action:

```json
{"endpoint":"https://pve.example.com:8006","credential":"lab-token"}
```

The origin must use HTTPS, without credentials, query parameters or an API path.
TLS hostname and certificate validation are mandatory. Install the cluster CA
in the control-plane trust store if needed; there is no insecure TLS switch.
Redirects are not followed. Requests have bounded connection and total deadlines.
Provider response bodies are excluded from errors.

Results include visible node capacity and QEMU templates. Templates are discovery
results only: their OS, architecture, runtime readiness, storage and network
compatibility have not been verified. The internal adapter also maps task UPID
status to pending/succeeded/failed/uncertain; only a stopped task with exitstatus
OK succeeds. Task output is not included in diagnostics.

Run `php tests/proxmox.php` or `composer test` for local fixture verification.
These tests do not prove live TLS, permissions or VM provisioning. The offered
lab host remains a later verification step after the local implementation.

## Create a VM

Prepare a QEMU NixOS template with the `tc-nixos` tag, the Trongate runtime,
cloud-init configured for the NoCloud datasource, a cloud-init drive, net0 on a
DHCP-enabled network, and the QEMU guest agent installed/enabled. Enable the agent
in Proxmox as well. Custom cloud-init overrides are rejected. The tag is an
administrator declaration, not proof of runtime readiness. Review the template's
CPU, memory, disks and network bridge: the full clone inherits them.

Clean instance-specific cloud-init state, machine identity and SSH host keys
before converting the stopped VM into a template. Configure cloud-init to generate
fresh Ed25519 host keys on first boot. Enable root SSH public-key login and disable
password authentication. Use the NoCloud datasource for the Proxmox template.

Each creation generates a dedicated Ed25519 keypair in memory. The private key is
stored only as an encrypted credential; queued jobs contain its reference and the
public key. Before first boot, the worker configures root access with that public
key and DHCP through Proxmox cloud-init. No manual SSH-key injection is required.

Choose a free cluster VMID and storage for a full copy of the template disks.
Use one canonical API origin per cluster for local operation locking. Do not
submit duplicate requests through alternate hostnames for the same cluster.

```json
{
  "endpoint": "https://pve.example.com:8006",
  "credential": "lab-token",
  "node": "pve",
  "template": 9000,
  "vmid": 201,
  "name": "web-201",
  "storage": "local-lvm",
  "confirm": true
}
```

Use the server creation page, then inspect the returned job ID
on the Jobs page while the normal worker runs. Confirmation authorizes the
clone's inherited resource allocation and disk use. The token needs clone,
allocation, storage, inspection and power-management rights for the selected
resources; discovery alone does not establish those permissions.

The worker journals intent before cloning, configuring SSH and starting, polls each saved Proxmox
task ID, checks VM ownership using an operation marker, and verifies the guest is
running. A timeout before a task ID is saved remains uncertain and retains its
lock. `reconcile` resumes observation but never blindly repeats that mutation.
An ambiguous cloud-init update is recovered only by reading back matching settings;
it is never blindly repeated. Inspect the Proxmox task history when a clone/start task ID is missing; this release has no
automated task-history recovery or cleanup of partially created VMs.

After boot, the worker obtains the guest's Ed25519 host public key and network
addresses through the TLS-authenticated Proxmox guest agent API. The token needs
guest-agent file-read and network inspection permissions as well as VM configuration
permissions. The control plane must be able to reach the guest network on port 22.
SSH strictly pins that host key; it never trusts an unauthenticated key scan.

The worker waits for SSH and NixOS application-runtime checks, then automatically
adds the server to inventory. Success means a connected, ready application server;
select it in app setup and build/deploy normally. No separate Connect server step
is needed. No app is automatically created by this command. Readiness retries do
not reconfigure or restart the VM. Existing queued requests without generated SSH
credentials require operator review rather than silently bypassing enrollment.

Live Proxmox integration, prepared-template boot behavior and the guided wizard
remain to be verified/implemented. No lab or production host is changed by the
local fixture tests.

API references: [Proxmox token authentication](https://github.com/proxmox/pve-docs/blob/master/pveum.adoc)
and [task status implementation](https://github.com/proxmox/pve-manager/blob/master/PVE/API2/Tasks.pm).
