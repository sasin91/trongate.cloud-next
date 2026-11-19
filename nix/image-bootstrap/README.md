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

Current build status: system and disk formatter built successfully on ARM;
provider installation and snapshot validation are pending Primary IP quota.
