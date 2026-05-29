# Architecture — Quick Start

The C4 model for this project lives in `*.c4` files in this directory and is
rendered via [LikeC4](https://likec4.dev). For the model overview, file
breakdown, and pre-rendered diagrams, see [`ARCHITECTURE.md`](./ARCHITECTURE.md).

## Run

Everything runs via `ghcr.io/likec4/likec4:1.56.0`; no local Node is required.
Make targets are invoked from the project root:

```bash
make c4-up         # live preview at http://localhost:5173
make c4-down       # stop
make c4-logs       # tail container logs
make c4-validate   # validate the model
```

Optionally — `LIKEC4_PORT=5174 make c4-up` if 5173 is busy.

## Exporting a diagram image

The LikeC4 web UI has an **Export** button in the toolbar of the current view —
save PNG/SVG straight from the browser. The 7 PNGs in [`diagrams/`](./diagrams/)
were generated this way and are committed so the model can be reviewed on
GitHub without running anything locally. Regenerate them after changing the
model.

## VS Code

Install the **LikeC4** extension by `likec4` for live preview and autocomplete.
