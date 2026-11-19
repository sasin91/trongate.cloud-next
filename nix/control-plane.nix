{ config, lib, pkgs, ... }:
let cfg = config.services.trongate-cloud;
    root = "${cfg.package}/share/tc";
    commandPackages = [ pkgs.openssh pkgs.nix pkgs.git cfg.deployPackage ];
    environment = { TC_STATE = "/var/lib/tc-control"; TC_KEY_FILE = "/var/lib/tc-control/key"; TC_BASE_URL = "https://${cfg.domain}"; TC_DSN = cfg.databaseDsn; TC_DB_USER = "tc"; TC_BUILD_REGISTRY = cfg.buildRegistry; TC_BUILD_REGISTRY_SECRET = cfg.buildRegistrySecret; };
in {
  options.services.trongate-cloud = {
    enable = lib.mkEnableOption "Trongate Cloud control plane";
    domain = lib.mkOption { type = lib.types.str; };
    package = lib.mkOption { type = lib.types.package; };
    deployPackage = lib.mkOption { type = lib.types.package; };
    buildRegistrySecret = lib.mkOption { type = lib.types.str; default = ""; description = "Encrypted credential reference containing application registry pull auth JSON"; };
    buildRegistry = lib.mkOption { type = lib.types.str; default = ""; description = "OCI registry and namespace for automatically named app builds; credentials stay on the builder"; };
    worker = lib.mkOption { type = lib.types.bool; default = true; };
    panel = lib.mkOption { type = lib.types.bool; default = true; };
    localDatabase = lib.mkOption { type = lib.types.bool; default = true; };
    databaseDsn = lib.mkOption { type = lib.types.str; default = "mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=tc"; };
    environmentFile = lib.mkOption { type = lib.types.nullOr lib.types.str; default = null; description = "Root-owned runtime file; never a Nix path containing secrets"; };
  };
  config = lib.mkIf cfg.enable {
    users.groups.tc = {};
    users.users.tc = { isSystemUser = true; group = "tc"; home = "/var/lib/tc-control"; createHome = true; };
    environment.systemPackages = [ cfg.package cfg.deployPackage pkgs.nix pkgs.openssh ];
    nix.settings = { experimental-features = [ "nix-command" "flakes" ]; trusted-users = [ "root" "tc" ]; };
    services.mysql = lib.mkIf cfg.localDatabase {
      enable = true; package = pkgs.mariadb;
      ensureDatabases = [ "tc" ];
      ensureUsers = [{ name = "tc"; ensurePermissions = { "tc.*" = "ALL PRIVILEGES"; }; }];
    };
    systemd.tmpfiles.rules = [ "d /var/lib/tc-control 0700 tc tc -" ];
    services.phpfpm.pools.tc = lib.mkIf cfg.panel {
      user = "tc"; group = "tc"; phpPackage = pkgs.php84;
      settings = { "listen.owner" = "caddy"; "listen.group" = "caddy"; "pm" = "dynamic"; "pm.max_children" = 8; "pm.start_servers" = 2; "pm.min_spare_servers" = 1; "pm.max_spare_servers" = 3; "clear_env" = false; };
    };
    systemd.services.phpfpm-tc = lib.mkIf cfg.panel {
      inherit environment;
      path = commandPackages;
      serviceConfig.EnvironmentFile = lib.mkIf (cfg.environmentFile != null) cfg.environmentFile;
    };
    services.caddy = lib.mkIf cfg.panel {
      enable = true;
      virtualHosts.${cfg.domain}.extraConfig = ''
        root * ${root}/public
        php_fastcgi unix/${config.services.phpfpm.pools.tc.socket}
        file_server
      '';
    };
    networking.firewall.allowedTCPPorts = lib.mkIf cfg.panel [ 80 443 ];
    systemd.services.tc-worker = lib.mkIf cfg.worker {
      description = "Trongate durable operation worker";
      wantedBy = [ "multi-user.target" ]; after = [ "network-online.target" "mysql.service" ];
      wants = [ "network-online.target" ];
      inherit environment;
      path = commandPackages;
      serviceConfig = {
        User = "tc"; Group = "tc"; UMask = "0077";
        ExecStart = "${cfg.package}/bin/tc worker"; Restart = "always"; RestartSec = 5;
        EnvironmentFile = lib.mkIf (cfg.environmentFile != null) cfg.environmentFile;
      };
    };
  };
}
