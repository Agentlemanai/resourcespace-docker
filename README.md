# resourcespace/docker
The official Docker image for ResourceSpace. Full build instructions can be found on our [Knowledge Base](https://www.resourcespace.com/knowledge-base/systemadmin/install_docker).

# Installation notes
* Before building the Docker image, change the db.env file replacing the default "change-me" passwords to secure values.
* When setting up ResourceSpace ensure you enter "mariadb" as the MySQL server instead of "localhost" and leave the "MySQL binary path" empty.

---

# Agentlemanai fork — running this on Coolify

This fork deploys ResourceSpace on `agentleman-ded-01` at **https://dam.garlomedia.com**,
as a Coolify **Git application with the Docker Compose build pack**, using
`docker-compose.prod.yml`. Upstream's `docker-compose.yaml`, `db.env` and `config.php`
are left as they are, so syncing with upstream stays conflict-free.

## Changes from upstream

- **The ResourceSpace revision is pinned** (`ARG RS_SVN_REVISION`, currently `29772`).
  Upstream checks out `releases/11.0`, a moving SVN branch with no tags, so two builds a
  week apart give different code. Upgrading is now a deliberate bump of that number.
- **`include/config.php` is a symlink to `/config/config.php`**, a named volume. The web
  installer writes the configuration there, so it survives a redeploy. Without this,
  every deploy would greet you with the installer again.
- **Upload limits raised** (`ARG PHP_UPLOAD_LIMIT`, default `4096M`, execution time 600s).
  Upstream caps uploads at 100 MB, which rejects video masters outright.
- **MariaDB pinned to `11.4`** (LTS) instead of the moving `mariadb` tag.
- **No published ports and no fixed container names**: Traefik routes the domain, and
  fixed names would collide with the other stacks on this server.
- **Health checks** on both services, so Coolify can tell you whether it is actually up.
- **Passwords come from Coolify's environment**, not the `change-me` values in `db.env`.

## The filestore lives on the Storage Box

The library is measured in terabytes; the server holds 437 GB. The filestore is therefore
the Hetzner Storage Box (sub-account `u609705-sub4`), mounted on the host over SMB and
bind-mounted into the container. Measured from this server: **0.45 ms latency, ~100 MB/s
write, ~109 MB/s read** — the box is in the same datacenter.

Safety: when the mount is absent, the mount point is an empty **write-protected**
directory, so ResourceSpace errors instead of silently writing originals to the local disk
and later reporting them missing.

## Environment variables (set in Coolify)

| Variable | Purpose |
|---|---|
| `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | Generated once, on the server. Losing them locks you out of the database. |
| `MYSQL_DATABASE`, `MYSQL_USER` | Default to `resourcespace` / `resourcespace_rw`. |
| `FILESTORE_PATH` | Host path of the SMB mount. Defaults to `/mnt/resourcespace/filestore`. |
| `RS_SVN_REVISION`, `PHP_UPLOAD_LIMIT` | Build arguments; the defaults above apply. |

## First install

1. Deploy, then open the site **immediately**: the installer is reachable by anyone until
   it is completed, exactly like FreeFrame's setup page.
2. In the installer, enter `mariadb` as the database server (not `localhost`) and leave
   the MySQL binary path **empty**.
3. Use the database name, user and password from Coolify's environment variables.

## Known follow-ups

- The image runs only ResourceSpace's daily hit-count cron. Features that rely on the
  **offline job queue** need an extra cron entry; add it when a plugin needs it.
- The database is covered by the server's nightly backup. The filestore is not: it already
  lives on the Storage Box, so copying it there again would be pointless. Enable Hetzner's
  snapshots on the box for accidental-deletion protection.

## Client portal plugin (`plugins/garlo_client_portal`)

Adds one API call, `garlo_client_portal_provision(client_name, collection_ref, [username], [fullname])`,
which gives a client a login that sees only their own approved material, the same way the
hand-made "Client - Xbody" / "Client - Virtualitica" setups work: a value in the Client field,
a "Client: X, approved only" search filter, a "Client - X" group (permissions copied from the
Virtualitica group, featured access limited to `j<collection_ref>`) and a user with a generated
password. Anything that already exists is reused; an existing user keeps its password.

Activepieces calls it when "DAM access" is ticked on a client company in Twenty and writes the
login back to the company. Settings (template group, Client field, Approved node) are in
`plugins/garlo_client_portal/config/config.php`.

After the first deploy that contains it: **Admin → System → Plugins → activate "Garlomedia client
portal provisioning"**. Activation is stored in the database, so it survives later deploys.
