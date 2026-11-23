{ config, lib, pkgs, ... }:
let cfg = config.services.trongate-runtime; in {
  options.services.trongate-runtime = {
    enable = lib.mkEnableOption "Trongate Podman runtime";
    builderPackage = lib.mkOption { type = lib.types.package; };
    localBuilds = lib.mkOption { type = lib.types.bool; default = true; };
    package = lib.mkOption { type = lib.types.package; };
    systemMemoryReserve = lib.mkOption { type = lib.types.ints.positive; default = 512; description = "Memory in MiB kept for the OS and proxy."; };
    databaseMemoryReserve = lib.mkOption { type = lib.types.ints.unsigned; default = 512; description = "Memory in MiB kept for application databases; adjust to their total budget."; };
  };
  config = lib.mkIf cfg.enable {
    environment.etc."tc-resources.json".text = builtins.toJSON {
      system_memory_mb = cfg.systemMemoryReserve;
      database_memory_mb = cfg.databaseMemoryReserve;
      build_memory_mb = if cfg.localBuilds then 1536 else 0;
      system_cpus = 0.25;
      database_cpus = 0.25;
      build_cpus = if cfg.localBuilds then 1 else 0;
    };
    virtualisation.podman.enable = true;
    users.users.tc-builder = lib.mkIf cfg.localBuilds {
      isNormalUser = true;
      uid = 1100;
      home = "/home/tc-builder";
      linger = true;
      subUidRanges = [{ startUid = 200000; count = 65536; }];
      subGidRanges = [{ startGid = 200000; count = 65536; }];
    };
    systemd.slices."user-1100" = lib.mkIf cfg.localBuilds {
      sliceConfig = { MemoryMax = "1536M"; CPUQuota = "100%"; TasksMax = 1024; };
    };
    services.openssh.enable = true;
    networking.firewall.allowedTCPPorts = [ 22 80 443 ];
    environment.systemPackages = [ cfg.package pkgs.caddy pkgs.curl pkgs.php84 pkgs.util-linux ] ++ lib.optionals cfg.localBuilds [ cfg.builderPackage pkgs.git pkgs.slirp4netns pkgs.fuse-overlayfs ];
    nix.settings.experimental-features = [ "nix-command" "flakes" ];
    # Retained profile generations + explicit runtime roots protect rollback inputs.
    nix.gc.automatic = lib.mkDefault false;
    systemd.tmpfiles.rules = [ "d /var/lib/tc/runtime 0700 root root -" ];
    systemd.generators.tc-runtime = pkgs.writeShellScript "tc-systemd-generator" ''
      export PATH=${lib.makeBinPath [ pkgs.coreutils ]}
      ${builtins.readFile ../runtime/tc-systemd-generator.sh}
    '';
    systemd.timers.tc-cleanup = {
      wantedBy = [ "timers.target" ];
      timerConfig = { OnBootSec = "15m"; OnUnitActiveSec = "1h"; };
    };
    systemd.services.tc-cleanup = {
      description = "Retire old application releases, preserving rollback";
      serviceConfig.Type = "oneshot";
      script = ''printf '%s' '{"action":"cleanup"}' | ${cfg.package}/bin/tc-runtime'';
    };
    systemd.services.tc-proxy = {
      description = "Trongate application routing";
      wantedBy = [ "multi-user.target" ]; after = [ "network-online.target" ];
      wants = [ "network-online.target" ];
      preStart = ''
        if ! test -f /var/lib/tc/runtime/Caddyfile; then
          printf '{\n admin 127.0.0.1:2019\n}\n' > /var/lib/tc/runtime/Caddyfile
        fi
      '';
      serviceConfig = {
        ExecStart = "${pkgs.caddy}/bin/caddy run --config /var/lib/tc/runtime/Caddyfile --adapter caddyfile";
        Restart = "always"; StateDirectory = "tc-caddy";
        Environment = "XDG_DATA_HOME=/var/lib/tc-caddy";
      };
    };
  };
}
