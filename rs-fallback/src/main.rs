//! CLI that turns a camera-RAW file into a JPEG preview using `raw_preview_rs`
//! (a libraw-backed Rust crate). The Nextcloud "Camera RAW Previews" PHP app
//! shells out to this binary only for files its pure-PHP pipeline cannot handle
//! — i.e. RAWs with no extractable embedded JPEG and no decodable TIFF.
//!
//! ## Usage
//!   camerarawpreviews <input>
//!
//! Reads <input> from disk, decodes it, and writes the JPEG preview to stdout.
//! EXIF orientation is already baked in by libraw, so callers need not rotate.
//!
//! Exit codes:
//!   0  success — JPEG written to stdout
//!   2  unprocessable — the input cannot be turned into a preview (no preview,
//!      or the native decoder crashed on this particular file)
//!   3  internal error — I/O, bad arguments, spawn failure, etc.
//!
//! ## Crash isolation
//! raw_preview_rs 0.1.2's native code can segfault on some inputs, and routinely
//! writes a perfectly good JPEG and *then* segfaults during teardown. To survive
//! that, the actual decode runs in a short-lived child process: this same binary
//! re-invoked as `__render <in> <out>`. The parent trusts the produced artifact
//! (a valid JPEG with an SOI marker) regardless of how the child exited, and
//! only reports failure when no usable JPEG was produced.

use std::io::Write;
use std::os::unix::process::ExitStatusExt;
use std::process::Command;

/// Argv[1] marker that makes this binary act as the one-shot render worker.
const RENDER_ARG: &str = "__render";
/// Exit code for "input cannot be turned into a preview".
const EXIT_UNPROCESSABLE: i32 = 2;
/// Exit code for an internal failure, e.g. I/O or a bad invocation.
const EXIT_INTERNAL: i32 = 3;

fn main() {
    let args: Vec<String> = std::env::args().collect();

    // Worker mode: `<bin> __render <input> <output>`. Decode and exit; this is
    // the isolated child the CLI spawns for itself.
    if args.get(1).map(String::as_str) == Some(RENDER_ARG) {
        std::process::exit(run_worker(args.get(2), args.get(3)));
    }

    // `--version` / `-V`: print name + version and exit 0. The Nextcloud app's
    // installer uses this to confirm a downloaded binary is the right one and
    // actually runs on this host before wiring it in.
    if matches!(args.get(1).map(String::as_str), Some("--version") | Some("-V")) {
        println!("{} {}", env!("CARGO_PKG_NAME"), env!("CARGO_PKG_VERSION"));
        std::process::exit(0);
    }

    std::process::exit(run_cli(&args));
}

/// Top-level CLI: `<bin> <input>` → JPEG bytes on stdout.
fn run_cli(args: &[String]) -> i32 {
    let input = match args.get(1) {
        Some(p) if !p.is_empty() && p.as_str() != RENDER_ARG => p,
        _ => {
            eprintln!(
                "usage: {} <input-raw-file>   (JPEG preview is written to stdout)",
                args.first().map(String::as_str).unwrap_or("camerarawpreviews")
            );
            return EXIT_INTERNAL;
        }
    };

    if !std::path::Path::new(input).is_file() {
        eprintln!("not a file: {input}");
        return EXIT_INTERNAL;
    }

    match render_in_child(input) {
        Ok(jpeg) => {
            if let Err(e) = std::io::stdout().write_all(&jpeg) {
                eprintln!("write stdout: {e}");
                return EXIT_INTERNAL;
            }
            0
        }
        Err(RenderError::Unprocessable(msg)) => {
            eprintln!("{msg}");
            EXIT_UNPROCESSABLE
        }
        Err(RenderError::Internal(msg)) => {
            eprintln!("{msg}");
            EXIT_INTERNAL
        }
    }
}

/// One-shot decode in the child process. Returns the process exit code.
fn run_worker(input: Option<&String>, output: Option<&String>) -> i32 {
    let (input, output) = match (input, output) {
        (Some(i), Some(o)) => (i, o),
        _ => {
            eprintln!("usage: {RENDER_ARG} <input> <output>");
            return EXIT_INTERNAL;
        }
    };
    match raw_preview_rs::process_any_image(input, output) {
        Ok(_exif) => 0,
        Err(e) => {
            // The message goes to stderr; the parent surfaces it on failure.
            eprintln!("{e}");
            EXIT_UNPROCESSABLE
        }
    }
}

enum RenderError {
    /// The input is not something we can turn into a preview. Also covers a
    /// crashed decoder child — that file simply has no preview for us.
    Unprocessable(String),
    /// Something went wrong on our side, e.g. temp-file I/O or spawn.
    Internal(String),
}

/// Spawn the one-shot render worker for `input`, returning the JPEG it writes.
///
/// A non-zero exit maps to Unprocessable/Internal; termination by signal (a
/// segfault in the native decoder) is treated as Unprocessable so a single bad
/// file is reported as "no preview" rather than a hard error.
fn render_in_child(input: &str) -> Result<Vec<u8>, RenderError> {
    let out = TempOut::new()?;
    let exe = std::env::current_exe()
        .map_err(|e| RenderError::Internal(format!("current_exe: {e}")))?;

    let result = Command::new(exe)
        .arg(RENDER_ARG)
        .arg(input)
        .arg(out.path_str())
        .output()
        .map_err(|e| RenderError::Internal(format!("spawn worker: {e}")))?;

    // Trust the artifact, not the exit code. raw_preview_rs/libraw routinely
    // writes a perfectly good JPEG and then segfaults during teardown, so a
    // crashed child can still have produced a usable preview. If the output is
    // a real JPEG (SOI marker), return it regardless of how the child exited.
    if let Ok(jpeg) = out.read() {
        if jpeg.starts_with(&[0xFF, 0xD8]) {
            return Ok(jpeg);
        }
    }

    if result.status.success() {
        // Exited cleanly but produced nothing usable.
        return Err(RenderError::Unprocessable("no preview produced".into()));
    }

    let stderr = String::from_utf8_lossy(&result.stderr).trim().to_string();
    match result.status.code() {
        Some(EXIT_UNPROCESSABLE) => Err(RenderError::Unprocessable(if stderr.is_empty() {
            "no preview could be produced".into()
        } else {
            stderr
        })),
        Some(EXIT_INTERNAL) => Err(RenderError::Internal(if stderr.is_empty() {
            "render worker internal error".into()
        } else {
            stderr
        })),
        Some(other) => Err(RenderError::Internal(format!(
            "render worker exited with code {other}: {stderr}"
        ))),
        None => {
            // Killed by a signal — almost always a segfault in libraw on this
            // input. Report it as "no preview", not a hard error.
            let sig = result.status.signal().unwrap_or(0);
            Err(RenderError::Unprocessable(format!(
                "decoder crashed on this input (signal {sig})"
            )))
        }
    }
}

/// A temp file the render worker writes the JPEG into, then we read back.
/// raw_preview_rs writes to a path rather than returning bytes, so we hand it a
/// throwaway path and slurp the result. Unlinked on drop.
struct TempOut {
    file: tempfile::NamedTempFile,
}

impl TempOut {
    fn new() -> Result<Self, RenderError> {
        let file = tempfile::Builder::new()
            .prefix("crp-")
            .suffix(".jpg")
            .tempfile()
            .map_err(|e| RenderError::Internal(format!("temp file: {e}")))?;
        Ok(Self { file })
    }

    fn path_str(&self) -> &str {
        self.file.path().to_str().unwrap_or("")
    }

    fn read(&self) -> Result<Vec<u8>, RenderError> {
        let bytes = std::fs::read(self.file.path())
            .map_err(|e| RenderError::Internal(format!("read preview: {e}")))?;
        if bytes.is_empty() {
            return Err(RenderError::Unprocessable("no preview produced".to_string()));
        }
        Ok(bytes)
    }
}
