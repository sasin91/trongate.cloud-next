# Prepared Proxmox runtime template

Build the repository's pinned x86_64 Linux image on a Linux builder with KVM:

```sh
nix build path:.#proxmox-template -L --out-link result-proxmox
```

The output is `result-proxmox/trongate-proxmox.qcow2`, a 16 GiB virtual disk
containing the runtime, local builder, NoCloud support and QEMU guest agent.
Use `path:.` while testing uncommitted files, so newly added Nix modules are
included. The derivation builds an image; it does not activate this system on
the builder. Budget additional disk for the Nix closure and temporary raw image.

Copy the resulting QCOW2 onto the Proxmox host. After checking that the chosen
VM ID is unused and the storage and bridge exist, an operator can import it:

```sh
# Example choices; inspect the actual cluster before using these values.
template_id=9000
storage=local-lvm
bridge=vmbr0
qm create "$template_id" --name trongate-nixos-template --memory 4096 --cores 2 \
  --bios seabios --scsihw virtio-scsi-pci --net0 "virtio,bridge=$bridge" \
  --agent enabled=1 --serial0 socket --vga serial0 --ostype l26
qm importdisk "$template_id" /path/to/trongate-proxmox.qcow2 "$storage"
# Inspect `qm config "$template_id"` and attach its exact unused0 volume:
qm set "$template_id" --scsi0 STORAGE:IMPORTED_VOLUME
qm set "$template_id" --ide2 "$storage:cloudinit" --citype nocloud \
  --boot order=scsi0 --tags tc-nixos --ciuser root --ipconfig0 ip=dhcp
qm template "$template_id"
```

The image uses BIOS GRUB and a filesystem label rather than a disk UUID. Attach
its boot disk through virtio-scsi as shown. Do not boot the template before
converting it: every clone must run cloud-init with a fresh instance identity,
SSH host keys, machine identity and authorized key. Do not bake credentials into
the image or configure custom cloud-init user/network snippets.

The template bridge must supply DHCP and a route reachable from the control
plane. The application supplies a generated public key before boot. The private
key stays encrypted on the control plane. Guest-agent access waits for cloud-init
SSH configuration so enrollment does not pin a transient host key.

4 GiB RAM and two CPUs are example template capacity, sufficient for the current
local-build admission thresholds with a small app. Confirm actual host capacity
before import. App provisioning inherits template resources and networking.

Validate a clone through the normal provisioning worker before treating the
template as ready: full clone task, SSH configuration, start task, guest-agent
identity, pinned SSH readiness and runtime inspection must all succeed. Building
this image alone does not prove those stages or application deployment.
