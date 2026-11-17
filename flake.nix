{
  description = "Trongate Cloud Next: self-hosted control plane and managed runtime";
  inputs.nixpkgs.url = "github:NixOS/nixpkgs/b349185bb79c7cf7ad8ea8c097931730bf473c71";
  inputs.deploy-rs.url = "github:serokell/deploy-rs/1cec0527bd09074bbee6d4461900ce5d58267217";
  inputs.deploy-rs.inputs.nixpkgs.follows = "nixpkgs";
  outputs = { self, nixpkgs, deploy-rs, ... }: let
    systems = [ "x86_64-linux" "aarch64-linux" ];
    forAll = nixpkgs.lib.genAttrs systems;
  in {
    packages = forAll (system: let pkgs = import nixpkgs { inherit system; }; in {
      runtime = pkgs.writeShellApplication {
        name = "tc-runtime";
        runtimeInputs = with pkgs; [ python3 podman systemd curl caddy nix util-linux which ];
        text = ''exec python3 ${./runtime/tc-runtime.py} "$@"'';
      };
      builder = pkgs.writeShellApplication {
        name = "tc-build";
        runtimeInputs = with pkgs; [ python3 podman git systemd ];
        text = ''exec python3 ${./runtime/tc-build.py} "$@"'';
      };
      default = pkgs.stdenvNoCC.mkDerivation {
        pname = "trongate-cloud-next"; version = "0.1.0"; src = self;
        nativeBuildInputs = [ pkgs.makeWrapper ];
        installPhase = ''
          mkdir -p $out/share/tc $out/bin
          cp -r engine lib modules public bin db nix runtime flake.nix flake.lock $out/share/tc/
          makeWrapper ${pkgs.php84}/bin/php $out/bin/tc --add-flags "$out/share/tc/bin/tc" \
            --prefix PATH : ${pkgs.lib.makeBinPath [ pkgs.openssh pkgs.nix deploy-rs.packages.${system}.default pkgs.git ]}
        '';
      };
    } // pkgs.lib.optionalAttrs (system == "x86_64-linux") {
      snapshot = import "${nixpkgs}/nixos/lib/make-disk-image.nix" {
        inherit pkgs; inherit (pkgs) lib;
        config = self.nixosConfigurations.snapshot.config;
        format = "raw"; partitionTableType = "legacy"; diskSize = 8192;
        name = "trongate-nixos-hetzner-x86";
      };
    });
    nixosModules.runtime = { pkgs, ... }: {
      imports = [ ./nix/runtime.nix ];
      services.trongate-runtime.package = self.packages.${pkgs.stdenv.hostPlatform.system}.runtime;
    };
    nixosModules.control-plane = { pkgs, ... }: {
      imports = [ ./nix/control-plane.nix ];
      services.trongate-cloud.package = self.packages.${pkgs.stdenv.hostPlatform.system}.default;
      services.trongate-cloud.deployPackage = deploy-rs.packages.${pkgs.stdenv.hostPlatform.system}.default;
    };
    nixosConfigurations.check = nixpkgs.lib.nixosSystem {
      system = "x86_64-linux";
      modules = [ self.nixosModules.control-plane ({...}: {
        services.trongate-cloud = { enable = true; domain = "control.example.com"; };
        boot.loader.grub.enable = false;
        fileSystems."/" = { device = "/dev/vda1"; fsType = "ext4"; };
        system.stateVersion = "26.05";
      }) ];
    };
    nixosConfigurations.snapshot = nixpkgs.lib.nixosSystem {
      system = "x86_64-linux";
      modules = [ self.nixosModules.runtime ./nix/snapshot.nix ];
    };
    checks.x86_64-linux.installation = self.nixosConfigurations.check.config.system.build.toplevel;
    checks.x86_64-linux.runtime-vm = import ./tests/runtime-vm.nix {
      pkgs = import nixpkgs { system = "x86_64-linux"; };
      inherit self;
    };
  };
}
