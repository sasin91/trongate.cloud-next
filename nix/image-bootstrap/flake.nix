{
  description = "Disposable Hetzner ARM image builder (overwrites its disk)";
  inputs.product.url = "github:sasin91/trongate.cloud-next/517621889093bbba7911610e180019486e4ea6fb";
  inputs.nixpkgs.follows = "product/nixpkgs";
  inputs.disko.url = "github:nix-community/disko/1dd30c29c88d99ce295f15e27f93c49bc5fbae07";
  inputs.disko.inputs.nixpkgs.follows = "nixpkgs";
  outputs = { product, nixpkgs, disko, ... }: {
    nixosConfigurations.image = nixpkgs.lib.nixosSystem {
      system = "aarch64-linux";
      modules = [
        product.nixosModules.runtime
        (product.outPath + "/nix/snapshot-arm.nix")
        disko.nixosModules.disko
        ./disk.nix
      ];
    };
  };
}
