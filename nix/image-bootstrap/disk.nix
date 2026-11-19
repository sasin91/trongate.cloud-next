{ ... }: {
  disko.devices.disk.system = {
    device = "/dev/sda";
    type = "disk";
    content = {
      type = "gpt";
      partitions = {
        ESP = {
          size = "512M";
          type = "EF00";
          content = { type = "filesystem"; format = "vfat"; mountpoint = "/boot"; };
        };
        root = {
          size = "100%";
          content = { type = "filesystem"; format = "ext4"; mountpoint = "/"; extraArgs = [ "-L" "nixos" ]; };
        };
      };
    };
  };
  fileSystems."/".autoResize = true;
}
