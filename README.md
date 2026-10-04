<p align="center">
  <img src="art/memry-logo.png" alt="memry" width="400">
</p>

# memry-server

Version **0.18.1** · [Changelog](CHANGELOG.md)

This is the server behind memry: a hosted, persistent memory MCP server for AI agents. Agents save and recall knowledge (decisions, bug fixes, conventions, session summaries) across sessions and projects.

## Using memry

You do not need to run this server to use memry. Install the [memry CLI](https://github.com/mrtheroi/memry-cli), then let it sign you in and connect your agent:

```bash
brew install mrtheroi/tap/memry
memry setup
```

## Development

Local setup, tests, configuration, deployment and the rest of the developer reference start at [docs/development.md](docs/development.md).

## Self-hosting (memry Community)

To run your own server, you need Docker with Compose v2 ([get Docker](https://docs.docker.com/get-docker/)). One script starts memry Community and connects your agents:

```bash
curl -fsSLo install.sh https://raw.githubusercontent.com/mrtheroi/memry-server/v0.18.1/install.sh && sh install.sh --email you@example.com
```

Read `install.sh` before you run it: it downloads the release's `docker-compose.yml`, writes a `.env` with a generated `APP_KEY` and database password to `~/memry-community`, starts PostgreSQL and the server, creates your user and token, and runs `memry setup` (installing the memry CLI with Homebrew if needed). Options, the manual setup and the rest of the guide are in [docs/self-hosting.md](docs/self-hosting.md).

## License

memry-server is released under the [MIT license](LICENSE).
