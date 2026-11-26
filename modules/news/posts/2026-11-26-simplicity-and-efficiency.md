---
title: Simplicity and efficiency
kind: writing
summary: From lean k3s on Hetzner through Proxmox, KVM, Incus, and NixOS back to a single bare-metal FreeBSD box with jails, a shared baseline, and ZFS snapshots.
---
It started with ambition—and the classic trap of building *scalable* before thinking *sustainable*.

I'll be brutally honest here: doing lean k3s on Hetzner "the right way" was more of an engineering challenge to myself than a business requirement. I wanted to properly use everything Kubernetes comes with before even thinking about actual service deployments.

I think I did a good job and pulled it off. Still, when the bill arrived for three or four medium-sized VPSes, a load balancer, a small control plane, a couple of S3 buckets, and extra IPs, my stomach sank a little. It matched my projections and expected costs, sure—but the raw invoice is only a small part of the economic picture. The bigger cost was the ongoing complexity tax of maintaining a whole cluster at a scale that didn't justify one.

Around then, Dafa mentioned he might have a spare box I could use. Even so, I was still stuck in the *scaling* mindset and the *containers-all-the-things* mindset. So I went down the rabbit hole of what's commonly praised online, filtered through ChatGPT, which led me straight to Proxmox.

From there I started pulling back the layers—looking past Proxmox's UI into raw KVM and `virt-manager`, then discovering and evaluating Incus as a lighter way to get isolation without full VM overhead. At the same time, I was thinking a lot about what I'd learned from container image builds. Compiling PHP extensions in particular turned out to be heavy, slow work, but by slicing an image into base and application layers, you can effectively skip the expensive parts on every deploy.

That train of thought pulled me into the deep end of the *Novella de la NixOS* docs. I got genuinely intrigued by NixOS's OCI image support—maybe a little blinded, honestly, by the elegance of declaring every config up front, building the base once, and shipping thin application layers on top.

Then I took a step back and found myself re-reading my own previous blog posts—specifically the ones about FreeBSD and AthletOS. Everything clicked. It was already what I needed, and I even had spare parts for a box sitting down in the basement.

At this scale, one box is enough. And FreeBSD gives me the exact wins I was chasing across four different Linux stacks, without any of the extra layers:

- **Shared baseline, thin jails:** Instead of juggling OCI layers or Nix derivations just to avoid rebuilding PHP extensions over and over, a shared read-only base and runtime mounted into vanilla FreeBSD jails solves the same problem natively. Build the heavy base once; keep each app thin, isolated, and fast.
- **Backups built into the filesystem:** ZFS paired with Sanoid turns snapshots, rollbacks, and replication into a quiet, policy-driven property of the storage layer rather than another brittle service to babysit.
- **Boring, stable, and efficient:** No orchestration daemon, no hypervisor sprawl, no cluster overhead—just a cohesive OS that stays out of the way.

The desire for simplicity, paired with the prospect of self-hosting on a platform I'm already familiar with and genuinely find joy in, sparked the gears toward making FreeBSD the new foundation. Sometimes the simplest solution that is good enough isn't a compromise—it's the whole point.

*Footnote: All of this brings to mind a quote I read a long time ago in the About Me section of [Zenwalk GNU Linux](https://www.zenwalk.org/)—a wonderfully lean distribution whose minimalist ethos has stuck with me ever since:*

> "A complex solution is good until you find a simpler one!"
