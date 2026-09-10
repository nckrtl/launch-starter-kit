# Project integrations

Open a project from the Projects table to see its links, Orbit instances, and GitHub activity.

The shared-knowledge manifest remains the source of truth. Connections are read-only.

```yaml
applications:
    - name: Example app
      orbit_app_id: 34
      repository: owner/repository
      development_url: https://example.test
slack:
    channels:
        - id: C123456
          name: example
routing:
    github:
        repositories:
            - owner/repository
```

An explicit `orbit_app_id` wins over `orbit_app_slug`, then a unique repository match. Without application mappings, Commander tries the project ID as an Orbit slug, then unique repository matches. Ambiguous or missing matches are shown, never silently merged. `runtime_url` is also supported. Manifest URLs do not imply a registered Orbit instance.

Set `COMMANDER_ORBIT_URL` to the gateway HTTPS address. Set `COMMANDER_ORBIT_CA` to a trusted CA file, or leave it empty to use system trust. The default when the variable is absent is `storage/app/orbit-gateway-ca.crt`. This installation has a copy of the public gateway root certificate fetched over authenticated SSH. No private keys are copied. Provision this file or system trust on another host. TLS verification stays enabled. The API uses this machine's WireGuard peer identity and existing Orbit access rules.

GitHub reads use `COMMANDER_GITHUB_BINARY` and the serving user's existing `gh` authentication. Run `gh auth status` as that user to diagnose access. No token is sent to the browser. A single GraphQL request reads up to 20 recently updated open PRs and 20 open issues per repository, with total counts and links to all results. Orbit caches for 30 seconds; GitHub for two minutes. Both load as separate deferred Inertia props.

Commander currently relies on its private Orbit network boundary, not application login. Anyone who can reach it can see these project details, including private-repository titles, and use the existing project editor. Do not publish this app beyond that trusted network without adding authentication and authorization.
