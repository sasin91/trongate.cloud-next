{ config, lib, pkgs, ... }:
let cfg = config.services.trongate-runtime; in {
  options.services.trongate-runtime = {
    enable = lib.mkEnableOption "Trongate Podman runtime";
    package = lib.mkOption { type = lib.types.package; };
  };
  config = lib.mkIf cfg.enable {
    virtualisation.podman.enable = true;
    services.openssh.enable = true;
    networking.firewall.allowedTCPPorts = [ 22 80 443 ];
    environment.systemPackages = [ cfg.package pkgs.caddy pkgs.curl pkgs.python3 pkgs.util-linux ];
    nix.settings.experimental-features = [ "nix-command" "flakes" ];
    # Retained profile generations + explicit runtime roots protect rollback inputs.
    nix.gc.automatic = lib.mkDefault false;
    systemd.tmpfiles.rules = [ "d /var/lib/tc/runtime 0700 root root -" ];
    systemd.generators.tc-runtime = pkgs.writeShellScript "tc-systemd-generator" ''
      export PATH=${lib.makeBinPath [ pkgs.coreutils ]}
      ${builtins.readFile ../runtime/tc-systemd-generator.sh}
    '';
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
