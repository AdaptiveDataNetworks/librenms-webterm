# Installing from the package repository

!!! warning "Not live yet"

    `packages.adaptivedatanetworks.com` is not serving yet. Until it is, install
    the gateway from the [release assets](bare-metal.md#2-the-gateway-package).

    Everything on this page is built and tested — a container test drives real
    `apt` and real `dnf` against a signed repository on every change — but the
    host itself still has to be stood up. **Remove this admonition when it is.**

Adding the repository means `apt upgrade` and `dnf upgrade` pick up new gateway
releases like any other package, instead of you downloading a file each time.

The plugin half is unaffected: it is a Composer package and continues to come
from Packagist through `./lnms plugin:add`.

## Debian and Ubuntu

```bash
# as root
install -d -m 0755 /usr/share/keyrings
curl -fsSL https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc \
  | gpg --dearmor -o /usr/share/keyrings/adn-archive-keyring.gpg

cat > /etc/apt/sources.list.d/adn.sources <<'EOF'
Types: deb
URIs: https://packages.adaptivedatanetworks.com/deb
Suites: stable
Components: main
Architectures: amd64 arm64
Signed-By: /usr/share/keyrings/adn-archive-keyring.gpg
EOF

apt update
apt install librenms-webterm-gw
```

??? info "Why `gpg --dearmor`, and why not `apt-key`"

    `apt-key` was deprecated and is gone from Debian 12 and Ubuntu 24.04. A key
    added with it applied to *every* repository on the system; `Signed-By` scopes
    the key to this repository alone, which is the point.

    The `--dearmor` matters because the bytes must match the filename. apt accepts
    an armored key named `.asc` or a binary one named `.gpg`, but armored content
    in a file named `.gpg` fails with:

    ```
    The following signatures couldn't be verified because the public key is not available: NO_PUBKEY ...
    ```

    which reads like a signing problem and is actually a file-format one. If you
    prefer to skip the conversion, save the key as
    `/usr/share/keyrings/adn-archive-keyring.asc` and point `Signed-By` at that
    instead — both are tested.

## RHEL, Rocky and Alma

```bash
# as root
rpm --import https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc

cat > /etc/yum.repos.d/adn.repo <<'EOF'
[adn]
name=Adaptive Data Networks
baseurl=https://packages.adaptivedatanetworks.com/rpm/
enabled=1
gpgcheck=1
repo_gpgcheck=1
gpgkey=https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc
metadata_expire=300
EOF

dnf install librenms-webterm-gw
```

`gpgcheck=1` verifies each package's own signature; `repo_gpgcheck=1` verifies
the repository metadata that lists them. Both are on deliberately — the second
is what stops someone substituting the index itself.

There is no `$releasever` or `$basearch` in the URL. The gateway is a static
binary with no shared-library dependencies, so one repository serves EL8, EL9
and EL10, and both architectures.

## Then finish the install

The package deliberately does not start the gateway — one started before its
allowed origins are set refuses every browser connection with a 403 and looks
broken. Run the setup helper it ships:

```bash
# as root
librenms-webterm-setup
```

See [installing on bare metal](bare-metal.md#3-run-the-setup-helper) for what it
does and how to run it unattended.

## Verifying the key

The signing key is RSA 4096. Check the fingerprint against the one published in
the repository's `SECURITY.md` before trusting it:

```bash
gpg --show-keys --with-fingerprint <(curl -fsSL https://packages.adaptivedatanetworks.com/adn-archive-keyring.asc)
```

??? question "Why RSA and not ed25519?"

    Because EL8 cannot use ed25519 for this. `rpm` 4.14, which EL8 ships, fails to
    import an ed25519 public key at all and reports

    ```
    /path/to/package.rpm: digests SIGNATURES NOT OK
    ```

    for anything signed with one — a message indistinguishable from a tampered
    package. Verified on `rockylinux:8` and `rockylinux:9`. RSA 4096 verifies on
    EL8, EL9, EL10 and every apt release we support.

## Removing the repository

=== "Debian / Ubuntu"

    ```bash
    # as root
    rm -f /etc/apt/sources.list.d/adn.sources
    rm -f /usr/share/keyrings/adn-archive-keyring.gpg
    apt update
    ```

=== "RHEL / Rocky / Alma"

    ```bash
    # as root
    rm -f /etc/yum.repos.d/adn.repo
    rpm -e --allmatches gpg-pubkey-$(rpm -q --qf '%{VERSION}-%{RELEASE}\n' gpg-pubkey \
        | head -1) 2>/dev/null || true
    dnf clean all
    ```

Removing the repository does not remove the gateway. Uninstall it separately —
see [uninstalling](uninstall.md).
