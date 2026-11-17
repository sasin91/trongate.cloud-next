{ lib, modulesPath, ... }: {
  imports = [ (modulesPath + "/profiles/qemu-guest.nix") ];
  services.trongate-runtime.enable = true;
  boot.loader.grub = { enable = true; device = "/dev/sda"; };
  fileSystems."/" = { device = "/dev/disk/by-label/nixos"; fsType = "ext4"; autoResize = true; };
  networking.useDHCP = lib.mkDefault true;
  services.cloud-init = {
    enable = true; network.enable = true;
    settings = { datasource_list = [ "Hetzner" ]; ssh_deletekeys = true; ssh_genkeytypes = [ "ed25519" ]; };
  };
  services.openssh.settings = { PasswordAuthentication = false; PermitRootLogin = "prohibit-password"; };
  system.stateVersion = "26.05";
}
