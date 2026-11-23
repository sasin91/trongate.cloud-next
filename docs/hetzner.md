> Historical record: this describes earlier work. The product now provisions only Proxmox/NixOS; old provider commands and image builders have been removed. See README.md and proxmox.md for current workflows.

# Hetzner project and NixOS snapshot bootstrap

Official documentation checked 2026-11-17:

- [Cloud API reference](https://docs.hetzner.cloud/reference/cloud): servers,
  actions, labels, images and rate limiting.
- [Creating a server](https://docs.hetzner.com/cloud/servers/getting-started/creating-a-server/):
  a snapshot can be selected as the server image.
- [Rescue system](https://docs.hetzner.com/cloud/servers/getting-started/rescue-system/):
  enabling Rescue requires a subsequent reboot and has a limited activation window.
- [Server FAQ](https://docs.hetzner.com/cloud/servers/faq/): custom ISO handling is
  support-assisted, and snapshot/server architectures must match.
- [Snapshots](https://docs.hetzner.com/cloud/servers/getting-started/taking-snapshots/):
  snapshots are copies of an existing server disk.

There is no assumption of an arbitrary raw-disk upload API. This PoC builds a raw
image locally, writes it through SSH to a **disposable selected VM in Rescue**,
then requests a provider snapshot of that VM. Snapshot disk bootstrap is x86_64
only; general host/runtime modules also expose aarch64 packages.

## Connect and inspect

Store a project-scoped API token using `tc credential`, then:

```sh
tc provider-connect provider.json
# {"id":"hetzner","credential":"hetzner-token"}
tc provider-options options.json
# {"provider":"hetzner"}
tc bootstrap-options options.json
```

The first command tests the connection. Options come from the live API, not a
hard-coded price/type catalog. `provider-options` lists available types/locations
and labeled snapshots. `bootstrap-options` lists stock system images.

## Explicit bootstrap sequence

1. Build the pinned 8 GiB raw NixOS image on x86 Linux with KVM:

   `nix build .#snapshot -L`

   The result contains `nixos.img`. It includes the runtime, GRUB on `/dev/sda`,
   DHCP/cloud-init (Hetzner datasource), root filesystem growth and SSH public-key
   provisioning. It contains no private keys or provider tokens.
2. Explicitly create a temporary VM using `tc bootstrap-create bootstrap.json`:

```json
{"provider":"hetzner","name":"tc-image-bootstrap","server_type":"TYPE_FROM_OPTIONS","location":"LOCATION_FROM_OPTIONS","image":123,"ssh_keys":[456],"confirm":true}
```

   Select an x86 stock **system image ID**. The same durable create/reconciliation
   mechanism is used; labels include `tc-operation=ID` and `tc-temporary=true`.
   The resulting provider ID/addresses are retained in `provider-server` records.
   Wait for the operation's provider action to succeed before proceeding.
3. `tc provider-rescue rescue.json` with
   `{"provider":"hetzner","provider_id":ID,"ssh_keys":[KEY_ID],"confirm":true}`;
   wait for completion, then explicitly `tc provider-reboot selected.json`.
   `selected.json` contains provider, provider_id and confirm=true.
4. Verify the Rescue host fingerprint through the provider console and create a
   dedicated known_hosts file. Do not automatically accept a changed host key.
   **The following overwrites `/dev/sda` on the selected bootstrap VM:**

```sh
bash bin/install-rescue-image.sh VM_ADDRESS PRIVATE_KEY VERIFIED_KNOWN_HOSTS \
  result/nixos.img --overwrite-bootstrap-disk
```

   The script requires the explicit destructive flag, checks the block device and
   refuses mounted partitions. Never select an existing application host.
5. Reboot the selected VM using the provider command. Independently verify the
   new NixOS SSH identity, then use normal server-scan/server-confirm/inspection.
   Include `"provider":"hetzner","provider_id":ID` in server-confirm to link
   enrollment to the selected provider's primary IPv4. Snapshot capture requires
   that linked ready NixOS record; reboot/deletion then share the host-operation lock.
   Confirm cloud-init imported the selected project SSH key and runtime readiness.
6. Before snapshot capture, clear instance-specific cloud-init/host identity state
   from the bootstrap machine using its console or verified SSH connection:

```sh
sudo cloud-init clean --logs --machine-id
sudo rm -f /etc/ssh/ssh_host_* /root/.ssh/authorized_keys
sudo poweroff
```

   This is only for the disposable bootstrap VM immediately before capture.
   Wait for the provider to report `off` with `tc provider-inspect selected.json`.
   Create the snapshot with `tc snapshot-create selected.json`, then wait for its
   action to succeed. The image receives `tc-nixos=true` and the stable operation
   label; its ID is retained in the external-operation record.
7. List options and select the new snapshot. Provision a disposable validation VM
   using normal `tc provision`, verify its independent SSH identity and readiness.
   Cloud-init must supply the newly selected SSH key and regenerate unique host keys.
8. Explicitly delete the bootstrap VM using `tc provider-delete selected.json`.
   Delete the validation VM with its own selected ID when finished. Keep the tested
   snapshot, or delete that exact image with `tc snapshot-delete image.json`.

## Resource accounting and ambiguity

Server IDs, action IDs, image IDs, stable operation IDs and temporary labels are
persisted. `tc operation` returns the external result; inspect the `external` and
`provider-server` records in the database when recovering an interrupted bootstrap.
Cleanup is explicit: the tool never guesses which provider resources are disposable.
Provider images and primary IPs can continue costing money after deleting a VM;
inspect the project's remaining resources in the Hetzner Console after cleanup.

On 429, transport failure or exhausted polling, the job remains visibly uncertain.
Run `tc reconcile` to observe again. A possibly accepted create is never blindly
resent, including when the label query is temporarily empty. Other ambiguous
mutations require inspection of the selected resource/action history. No provider
credentials or live billable-resource authorization were supplied for verification
of this repository; these steps must be exercised in an isolated project before
claiming a verified live Hetzner flow.
