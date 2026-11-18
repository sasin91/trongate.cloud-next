<?php
declare(strict_types=1);
require dirname(__DIR__).'/modules/operations/bootstrap.php';
umask(0077);
try {
    Encryptor::validate_key(installation_key());
    $p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $server=(new Operations_model('operations'))->get('server',$p['server']);
    $data=DeployRs::configuration($server,$p);
    // Hardware module is trusted installation-administrator input, never web input.
    $hardware=file_get_contents($p['hardware_file']);
    $source=dirname(__DIR__);
    if (!is_file($source.'/flake.lock')) { throw new RuntimeException('Run nix flake lock before freezing a revision'); }
    $revision=hash('sha256',$data.$hardware.file_get_contents(__FILE__).file_get_contents($source.'/flake.nix').file_get_contents($source.'/flake.lock').file_get_contents($source.'/runtime/tc-runtime.py').file_get_contents($source.'/runtime/tc-systemd-generator.sh').file_get_contents($source.'/nix/runtime.nix'));
    $dir=state_dir().'/revisions/'.$revision;
    if (is_file($dir.'/expected-system')) { echo $revision."\n"; exit; }
    if (!is_dir($dir.'/source/runtime')) { mkdir($dir.'/source/runtime',0700,true); }
    if (!is_dir($dir.'/source/nix')) { mkdir($dir.'/source/nix',0700,true); }
    foreach (['flake.nix','flake.lock','runtime/tc-runtime.py','runtime/tc-systemd-generator.sh','nix/runtime.nix','nix/control-plane.nix'] as $f) { copy($source.'/'.$f,$dir.'/source/'.$f); }
    file_put_contents($dir.'/inventory.json',$data); file_put_contents($dir.'/hardware.nix',$hardware);
    $flake=<<<'NIX'
{
  inputs.product.url = "path:./source";
  inputs.nixpkgs.follows = "product/nixpkgs";
  inputs.deploy-rs.follows = "product/deploy-rs";
  outputs = { self, product, nixpkgs, deploy-rs, ... }: let
    inventory = builtins.fromJSON (builtins.readFile ./inventory.json);
  in {
    nixosConfigurations.target = nixpkgs.lib.nixosSystem {
      system = inventory.system;
      modules = [ ./hardware.nix product.nixosModules.runtime {
        networking.hostName = inventory.hostname;
        services.trongate-runtime.enable = true;
        services.openssh.ports = [ inventory.port ];
        system.stateVersion = nixpkgs.lib.mkDefault "26.05";
      } ];
    };
    deploy.nodes.target = {
      hostname = inventory.address; sshUser = inventory.user;
      magicRollback = true; autoRollback = true; activationTimeout = 240; confirmTimeout = 60;
      profiles.system = {
        user = "root";
        path = deploy-rs.lib.${inventory.system}.activate.nixos self.nixosConfigurations.target;
      };
    };
    checks = builtins.mapAttrs (_: lib: lib.deployChecks self.deploy) deploy-rs.lib;
  };
}
NIX;
    file_put_contents($dir.'/flake.nix',$flake);
    $process=new Process;
    $result=$process->run(['nix','flake','lock',$dir],'',600);
    if ($result['exit']!==0) { throw new RuntimeException('Configuration lock failed: '.$result['err']); }
    $result=$process->run(['nix','build','--out-link',$dir.'/system','--print-out-paths',$dir.'#nixosConfigurations.target.config.system.build.toplevel'],'',3600);
    if ($result['exit']!==0) { throw new RuntimeException('Configuration build failed: '.$result['err']); }
    file_put_contents($dir.'/expected-system',trim($result['out']));
    echo $revision."\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
