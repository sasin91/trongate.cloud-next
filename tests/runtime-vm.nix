{ pkgs, self }:
let
  web = pkgs.runCommand "tc-fixture-web" {} ''
    mkdir -p $out/app/public
    echo 'hello from the immutable release' > $out/app/public/index.html
  '';
  good = pkgs.dockerTools.buildLayeredImage {
    name = "tc-fixture"; tag = "good";
    contents = [ pkgs.python3 web ];
    config.Cmd = [ "${pkgs.python3}/bin/python3" "-m" "http.server" "8080" "--directory" "/app/public" ];
  };
  bad = pkgs.dockerTools.buildLayeredImage {
    name = "tc-fixture"; tag = "bad";
    contents = [ pkgs.coreutils ];
    config.Cmd = [ "${pkgs.coreutils}/bin/sleep" "600" ];
  };
in pkgs.testers.runNixOSTest {
  name = "trongate-runtime";
  nodes.machine = { ... }: {
    imports = [ self.nixosModules.runtime ];
    services.trongate-runtime.enable = true;
    services.dockerRegistry = { enable = true; listenAddress = "127.0.0.1"; port = 5000; };
    virtualisation.containers.registries.insecure = [ "localhost:5000" ];
    environment.systemPackages = [ pkgs.php84 pkgs.openssh pkgs.jq ];
    virtualisation.memorySize = 3072;
    virtualisation.diskSize = 8192;
    services.openssh.settings.PermitRootLogin = "prohibit-password";
  };
  nodes.control = { pkgs, ... }: {
    imports = [ self.nixosModules.control-plane ];
    services.trongate-cloud = { enable = true; domain = "control.localhost"; };
    environment.systemPackages = [ pkgs.curl ];
    virtualisation.memorySize = 1024;
  };
  testScript = ''
    import json
    machine.start()
    machine.wait_for_unit("tc-proxy.service")
    machine.wait_for_unit("docker-registry.service")
    machine.succeed("podman load -i ${good}")
    machine.succeed("podman load -i ${bad}")
    machine.succeed("podman push --tls-verify=false --digestfile=/tmp/good.digest tc-fixture:good docker://localhost:5000/demo:good")
    machine.succeed("podman push --tls-verify=false --digestfile=/tmp/bad.digest tc-fixture:bad docker://localhost:5000/demo:bad")
    good_digest = machine.succeed("cat /tmp/good.digest").strip()
    bad_digest = machine.succeed("cat /tmp/bad.digest").strip()
    machine.succeed("mkdir -p /root/.ssh; ssh-keygen -t ed25519 -N \"\" -f /root/.ssh/id_ed25519; cp /root/.ssh/id_ed25519.pub /root/.ssh/authorized_keys; chmod 600 /root/.ssh/authorized_keys")
    machine.succeed("mkdir -p /var/lib/tc-control; cp -r ${self} /tmp/product; chmod -R u+w /tmp/product")
    machine.succeed("TC_STATE=/var/lib/tc-control TC_DSN=sqlite:/var/lib/tc-control/test.db php /tmp/product/bin/tc init <<'EOF'\n{\"name\":\"admin\",\"password\":\"disposable-test-password\"}\nEOF")
    def cli(command, payload):
        import shlex
        result = machine.succeed("printf %s " + shlex.quote(json.dumps(payload)) + " | TC_STATE=/var/lib/tc-control TC_DSN=sqlite:/var/lib/tc-control/test.db php /tmp/product/bin/tc " + command)
        return json.loads(result)
    # Keep the private key inside the VM: the test driver logs command arguments.
    machine.succeed("php -r 'echo json_encode([\"id\"=>\"test-key\",\"value\"=>file_get_contents(\"/root/.ssh/id_ed25519\")]);' | TC_STATE=/var/lib/tc-control TC_DSN=sqlite:/var/lib/tc-control/test.db php /tmp/product/bin/tc credential")
    scanned = cli("server-scan", {"host": "127.0.0.1", "port": 22, "user": "root", "credential": "test-key"})
    fingerprint = machine.succeed("ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub | cut -d' ' -f2").strip()
    assert scanned["fingerprint"] == fingerprint
    enrolled = cli("server-confirm", {"id": "web", "enrollment": scanned["enrollment"], "fingerprint": fingerprint})
    assert enrolled["application_ready"], enrolled
    cli("app-create", {"id": "demo", "server": "web"})
    def deploy(digest):
        release = cli("release", {"app": "demo", "release": {"image": "localhost:5000/demo@"+digest, "domain": "demo.localhost", "health": "/", "mounts": {"uploads": "/app/uploads"}}})
        op = cli("deploy", {"app": "demo", "release": release, "public_check": False})
        machine.succeed("TC_STATE=/var/lib/tc-control TC_DSN=sqlite:/var/lib/tc-control/test.db php /tmp/product/bin/tc worker --once")
        return cli("operation", {"operation": op})
    first = deploy(good_digest)
    assert first["job"]["status"] == "succeeded", first
    machine.succeed("echo retained > /var/lib/tc/runtime/apps/demo/data/uploads/keep")
    second = deploy(good_digest)
    assert second["job"]["status"] == "succeeded", second
    failed = deploy(bad_digest)
    assert failed["job"]["status"] == "failed", failed
    rollback = cli("rollback", {"app": "demo", "public_check": False})
    machine.succeed("TC_STATE=/var/lib/tc-control TC_DSN=sqlite:/var/lib/tc-control/test.db php /tmp/product/bin/tc worker --once")
    assert cli("operation", {"operation": rollback})["job"]["status"] == "succeeded"
    machine.succeed("test $(cat /var/lib/tc/runtime/apps/demo/data/uploads/keep) = retained")
    machine.wait_until_succeeds("curl -ksf --resolve demo.localhost:443:127.0.0.1 https://demo.localhost | grep 'immutable release'")
    # Real host-key rotation must fail closed on the next normal CLI inspection.
    machine.succeed("rm /etc/ssh/ssh_host_ed25519_key /etc/ssh/ssh_host_ed25519_key.pub; ssh-keygen -t ed25519 -N \"\" -f /etc/ssh/ssh_host_ed25519_key; systemctl restart sshd")
    machine.fail("echo '{\"server\":\"web\"}' | TC_STATE=/var/lib/tc-control TC_DSN=sqlite:/var/lib/tc-control/test.db php /tmp/product/bin/tc server-inspect")
    # Boot the supported installation module with its real MariaDB/socket auth,
    # PHP-FPM, Caddy and worker rather than only evaluating its options.
    control.start()
    control.wait_for_unit("mysql.service")
    control.succeed("printf '%s' '{\"name\":\"admin\",\"password\":\"disposable-test-password\"}' | sudo -u tc env TC_STATE=/var/lib/tc-control tc init")
    control.wait_for_unit("phpfpm-tc.service")
    control.wait_for_unit("caddy.service")
    control.wait_until_succeeds("curl -ksf --resolve control.localhost:443:127.0.0.1 https://control.localhost/ | grep 'Sign in'")
    control.succeed("sudo -u tc env TC_STATE=/var/lib/tc-control tc inventory </dev/null")
    control.wait_for_unit("tc-worker.service")
  '';
}
