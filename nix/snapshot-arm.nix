{ lib, modulesPath, ... }: {
  imports = [ (modulesPath + "/profiles/qemu-guest.nix") ];
  services.trongate-runtime.enable = true;
  boot.loader.systemd-boot.enable = true;
  boot.loader.efi.canTouchEfiVariables = false;
  boot.initrd.availableKernelModules = [ "virtio_pci" "virtio_scsi" "virtio_blk" ];
  networking.useDHCP = lib.mkDefault true;
  networking.useNetworkd = true;
  services.cloud-init = {
    enable = true;
    network.enable = true;
    settings = {
      datasource_list = [ "Hetzner" ];
      disable_root = false;
      ssh_deletekeys = true;
      ssh_genkeytypes = [ "ed25519" ];
      growpart = { mode = "auto"; devices = [ "/" ]; };
      resize_rootfs = true;
    };
  };
  services.openssh.settings = {
    PasswordAuthentication = false;
    PermitRootLogin = "prohibit-password";
  };
  system.stateVersion = "26.05";
}
