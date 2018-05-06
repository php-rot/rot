# Rot: Decomposing Composer

_This is at the proof-of-concept stage. There's is a lot still to implement._

Rot : Composer :: [CoreOS Partitions](https://coreos.com/os/docs/latest/sdk-disk-partitions.html) : DNF/apt-get

Rot is a Composer plugin that allows web applications to package releases so
that end-users automatically receive updates. It uses switchable vendor and
public asset directories for updates and changes, just as CoreOS provides
switchable `/usr` directories for the same reason.

Like CoreOS, Rot makes updating easy and low-risk by never updating things
in-place. Instead, updates occur to the "partition" that isn't in use. Once an
update is fully ready, the deployed website can atomically switch to the updated
code and assets. If the update hasn't caused side-effects (e.g. a database
schema update), rolling back is just as safe and atomic.

## Instructions: Web Framework Maintainers

### Installing the Rot Plug-In for Composer

1. `composer global require php-rot/rot`

### Publishing a Release

1. `composer rot release`
1. Copy the generated `.rot` file to a server publicly accessible over HTTPS.

### Packaging the Project for Distribution

Rot-based distribution packages don't contain any actual project code or assets,
so you don't need to do this every release, only when Rot has updated its
distribution packaging implementation (which is hopefully rare).

1. `composer rot distribute https://example.com/path/to/project.rot`
1. Publish the generated `.tar.gz`. This is what end-users will deploy to
   install the application to their server(s).
   
## Instructions: End-Users

1. Download the `.tar.gz` for the project and extract it to the web server.
1. Configure the web server to route requests (for files that don't exist on
   disk) to `index.php`.
1. Browse to the site in a browser to download, install, and configure the
   website. 
1. The website will automatically update when it detects a new, packaged
   release.

## Action Items

- Convert to a Composer plug-in.
- Have the release tool generate a fully materialized (recursively) manifest.
- Provide validation for `.rot` manifests independent of HTTPS.
- Make updates to packages less/non-racy:
  - Randomize the order of package checking to avoid redundant work.
  - Extract the project archives over their existing content and clean up files absent from the new project archive.
  - Alternatively, extract to a unique directory and move it in place once complete (and handle conflicts in the renaming safely).
- Treat other partitions as a package source.
- Validate presence of expected data in the opcache?
