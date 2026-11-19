# ARM Hetzner NixOS image bootstrap

This flake builds the installation system and disk formatter for a disposable
CAX11 image-builder VM. It uses pinned product, Nixpkgs and disko inputs. The
result has the Trongate runtime, Podman, Caddy, cloud-init, UEFI boot and root
filesystem growth. No API tokens, SSH private keys or fixed authorized keys
are included in the Nix configuration.

Build on aarch64 Linux:

```sh
nix build ./nix/image-bootstrap#nixosConfigurations.image.config.system.build.toplevel -o image-system
nix build ./nix/image-bootstrap#nixosConfigurations.image.config.system.build.diskoScript -o image-disko
```

These outputs are an installation closure and disk formatter, not a published
Hetzner snapshot. Installation must target a newly created disposable ARM VM:
**the formatter overwrites /dev/sda**. Supply a temporary SSH identity outside
the Nix store, verify the initial host key, and install with nixos-anywhere.
After installation verify NixOS, tc-runtime, Podman and tc-proxy. Remove temporary
credentials and instance-specific cloud-init state before powering off and
capturing a snapshot. Validate a new VM created from that snapshot with a fresh
SSH key before adding the tc-nixos=true label. Delete only the exact temporary
resources created for this build after validation.

Validated on Hetzner CAX11 in Helsinki on 2026-11-19. Snapshot 458348969
(`Trongate NixOS 26.05 ARM runtime 2026-11-19`) is available in the connected
provider project. It requires an ARM server with at least a 40 GB disk.

A fresh VM created from the snapshot passed NixOS/architecture, cloud-init,
SSH key provisioning, tc-runtime protocol, Caddy service and Podman container
checks. The original builder SSH key was rejected by the clone. Temporary
servers and provider SSH keys were deleted after validation. These are image
smoke tests; application deployment and HTTPS still need their own end-to-end test.

Hetzner retains the original installation image's `os_flavor=debian` metadata
on the snapshot. The installed and boot-tested OS is NixOS 26.05; the description
and `tc-nixos=true` label identify it. Cloud-init completed without errors but
reported a nonfatal Nixpkgs `keys-to-console` helper-path warning. Key injection
and SSH identity checks passed; use `ssh-keygen -lf` to inspect the host key.

