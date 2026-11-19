{ config, lib, pkgs, ... }:
let cfg = config.services.trongate-builder; in {
  options.services.trongate-builder = {
    enable = lib.mkEnableOption "Dedicated Trongate build host";
    package = lib.mkOption { type = lib.types.package; };
    authorizedKeys = lib.mkOption { type = lib.types.listOf lib.types.str; default = []; };
    registryDomain = lib.mkOption { type = lib.types.nullOr lib.types.str; default = null; };
    registryPasswordFile = lib.mkOption {
      type = lib.types.str;
      default = "/var/lib/tc-builder-secrets/registry.htpasswd";
      description = "Runtime bcrypt htpasswd file, readable by docker-registry; never put credentials in the Nix store";
    };
  };
  config = lib.mkIf cfg.enable {
    assertions = [{ assertion = cfg.authorizedKeys != []; message = "Configure a dedicated builder SSH public key."; }];
    virtualisation.podman.enable = true;
    users.users.tc-builder = {
      isNormalUser = true;
      uid = 1100;
      home = "/home/tc-builder";
      linger = true;
      subUidRanges = [{ startUid = 200000; count = 65536; }];
      subGidRanges = [{ startGid = 200000; count = 65536; }];
      openssh.authorizedKeys.keys = cfg.authorizedKeys;
    };
    services.openssh.enable = true;
    services.openssh.settings.PasswordAuthentication = false;
    environment.systemPackages = [ cfg.package pkgs.git pkgs.python3 pkgs.slirp4netns pkgs.fuse-overlayfs ];
    networking.firewall.allowedTCPPorts = [ 22 ] ++ lib.optionals (cfg.registryDomain != null) [ 80 443 ];
    systemd.slices."user-1100".sliceConfig = { MemoryMax = "3G"; TasksMax = 2048; };
    services.dockerRegistry = lib.mkIf (cfg.registryDomain != null) {
      enable = true;
      listenAddress = "127.0.0.1";
      extraConfig.auth.htpasswd = { realm = "Trongate images"; path = cfg.registryPasswordFile; };
    };
    services.caddy = lib.mkIf (cfg.registryDomain != null) {
      enable = true;
      virtualHosts = lib.optionalAttrs (cfg.registryDomain != null) {
        ${cfg.registryDomain}.extraConfig = "reverse_proxy 127.0.0.1:5000";
      };
    };
    systemd.tmpfiles.rules = lib.optionals (cfg.registryDomain != null) [ "d /var/lib/tc-builder-secrets 0750 root docker-registry -" ];
  };
}
