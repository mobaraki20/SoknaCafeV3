# ADR 0008 — Configurable Local Web endpoint

Status: Accepted  
Date: 2026-09-28

## Context

SOKNA V3 must coexist with other local web applications. Port 80 may already belong to a legacy café system, IIS, HTTP.sys, or another web server. Hardcoding port 80, 443, `localhost`, or a different origin independently in Apache, Browser Setup, Runtime, or Print Agent creates configuration drift and can break Runtime triggers or Print Agent browser-origin checks.

## Decision

SOKNA Local Web uses one configurable, loopback-only endpoint.

- Canonical host: `127.0.0.1`.
- Allowed schemes: HTTP/HTTPS, with current Local infrastructure using HTTP.
- Local Web port must be configurable and is restricted to 1024–65535.
- Default port for a new infrastructure install is `18080`.
- Port 80 is not required and is not taken over from another application.
- Prerequisites Setup owns initial port selection and persists the endpoint in `Infrastructure\infrastructure-state.json`.
- Apache serves `<SOKNA root>\Web\public`, while the clean-install Local Web ZIP is extracted directly into `<SOKNA root>\Web`.
- Browser Setup derives the actual Apache port from the running request, canonicalizes the host to `127.0.0.1`, persists `local.base_url`, and generates the Windows Services Pairing file.
- Runtime `localBaseUrl`, Print Agent `server_base_url`, and `local_bridge_allowed_origin` must use the same origin from the Pairing file.
- Public Edge is unrelated to this Local endpoint and remains optional for local-only operation.

## Port conflict handling

Before Apache configuration/start, Prerequisites Setup attempts a real loopback bind. If the selected port cannot bind, it identifies the owner when possible and offers a free candidate. A port change is persisted; Windows Services are activated from Browser Setup Pairing so they receive the same final origin.

An older failed/incomplete SOKNA Apache configuration on port 80 is treated as migration input. It must not crash the new Setup or force port 80 back into use.

## Canonical origin

Browser requests using `localhost:<port>` are redirected to the equivalent `127.0.0.1:<port>` origin. This prevents Local Web, Runtime, and Print Agent from disagreeing because browsers treat `localhost` and `127.0.0.1` as different origins.

## Test scenarios

Release qualification must cover at least:
1. another application already using port 80;
2. default SOKNA port 18080 free;
3. default SOKNA port occupied and fallback selected;
4. alternate ports including 18081 and 23456;
5. Browser Setup + real MariaDB on a non-default port;
6. generated Pairing carries the exact Local origin;
7. Runtime accepts valid arbitrary loopback ports and rejects non-loopback endpoints;
8. Print Agent accepts the configured loopback origin and rejects a non-origin ServerBaseUrl;
9. old/incomplete Apache configuration on port 80 migrates without Setup startup failure;
10. fully offline prerequisite acquisition remains independent of endpoint selection.
