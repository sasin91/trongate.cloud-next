{ lib, modulesPath, ... }: {
  imports = [ (modulesPath + "/profiles/qemu-guest.nix") ];

  services.trongate-runtime.enable = true;
  services.qemuGuest.enable = true;
  boot.loader.grub = { enable = true; device = "/dev/sda"; };
  boot.initrd.availableKernelModules = [ "virtio_pci" "virtio_scsi" "virtio_blk" ];
  boot.kernelParams = [ "console=tty0" "console=ttyS0,115200n8" ];
  fileSystems."/" = {
    device = "/dev/disk/by-label/nixos";
    fsType = "ext4";
    autoResize = true;
  };

  # Proxmox's NoCloud drive owns networking; avoid competing DHCP clients.
  networking.hostName = lib.mkDefault "trongate";
  networking.useDHCP = false;
  networking.useNetworkd = true;
  services.cloud-init = {
    enable = true;
    network.enable = true;
    settings = {
      datasource_list = [ "NoCloud" ];
      disable_root = false;
      ssh_pwauth = false;
      ssh_deletekeys = true;
      ssh_genkeytypes = [ "ed25519" ];
      growpart = { mode = "auto"; devices = [ "/" ]; };
      resize_rootfs = true;
    };
  };
  services.openssh = {
    hostKeys = [{ path = "/etc/ssh/ssh_host_ed25519_key"; type = "ed25519"; }];
    settings = {
      PasswordAuthentication = false;
      KbdInteractiveAuthentication = false;
      PermitRootLogin = "prohibit-password";
    };
  };

  # Cloud-init's ssh module creates each clone's unique keys in the config
  # stage. Do not expose an earlier sshd-generated key through the guest agent.
  systemd.services.cloud-config.after = [ "cloud-init.service" ];
  systemd.services.sshd = {
    requires = [ "cloud-config.service" ];
    after = [ "cloud-config.service" ];
  };
  systemd.services.sshd-keygen = {
    requires = [ "cloud-config.service" ];
    after = [ "cloud-config.service" ];
  };
  systemd.services.qemu-guest-agent = {
    requires = [ "cloud-config.service" ];
    after = [ "cloud-config.service" ];
  };

  system.stateVersion = "26.05";
}
