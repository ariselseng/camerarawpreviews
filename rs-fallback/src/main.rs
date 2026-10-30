//! CLI that turns a camera-RAW file into a JPEG preview using `raw_preview_rs`
//! (a libraw-backed Rust crate). The Nextcloud "Camera RAW Previews" PHP app
//! shells out to this binary only for files its pure-PHP pipeline cannot handle
//! — i.e. RAWs with no extractable embedded JPEG and no decodable TIFF.
//!
//! ## Usage
//!   camerarawpreviews <input>
//!   camerarawpreviews --jxl <input.jxl>
//!
//! `<input>` reads a camera-RAW file from disk, decodes it, and writes the JPEG
//! preview to stdout. EXIF orientation is already baked in by libraw, so callers
//! need not rotate.
//!
//! `--jxl <input.jxl>` decodes an embedded JPEG XL preview (bare codestream or
//! container), converts it to sRGB, caps the long edge at 4096 px and writes a
//! baseline JPEG to stdout. The PHP app uses this for DNG/TIFF previews whose
//! JXL image is larger than the best embedded JPEG.
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

    // `--jxl <path>` / `--jxl=<path>`: convert an embedded JPEG XL preview to
    // JPEG. Kept separate from the libraw renderer so neither path can regress
    // the other.
    if args.get(1).map(String::as_str) == Some("--jxl") {
        match args.get(2) {
            Some(path) if !path.is_empty() => std::process::exit(run_jxl(path)),
            _ => {
                eprintln!("usage: {} --jxl <input-jxl-file>", program_name(&args));
                std::process::exit(EXIT_INTERNAL);
            }
        }
    }
    if let Some(path) = args.get(1).and_then(|a| a.strip_prefix("--jxl=")) {
        if path.is_empty() {
            eprintln!("usage: {} --jxl <input-jxl-file>", program_name(&args));
            std::process::exit(EXIT_INTERNAL);
        }
        std::process::exit(run_jxl(path));
    }

    std::process::exit(run_cli(&args));
}

fn program_name(args: &[String]) -> &str {
    args.first().map(String::as_str).unwrap_or("camerarawpreviews")
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

/// `--jxl <path>`: decode an embedded JPEG XL preview and write JPEG to stdout.
fn run_jxl(input: &str) -> i32 {
    match convert_jxl(input) {
        Ok(jpeg) => {
            if let Err(e) = std::io::stdout().write_all(&jpeg) {
                eprintln!("write stdout: {e}");
                return EXIT_INTERNAL;
            }
            0
        }
        Err(msg) => {
            eprintln!("{msg}");
            EXIT_UNPROCESSABLE
        }
    }
}

/// Long-edge cap for converted previews. Larger JXL previews are downscaled,
/// smaller ones are never upscaled.
const MAX_PREVIEW_EDGE: u32 = 4096;
/// JPEG quality for the converted preview.
const JPEG_QUALITY: u8 = 90;

/// Decode a bare JPEG XL codestream or container to an sRGB JPEG.
fn convert_jxl(input: &str) -> Result<Vec<u8>, String> {
    use jpeg_encoder::{ColorType, Encoder};
    use jxl_oxide::{EnumColourEncoding, JxlImage, RenderingIntent};

    let mut image =
        JxlImage::open_with_defaults(input).map_err(|e| format!("decode JXL header: {e}"))?;
    image.request_color_encoding(EnumColourEncoding::srgb(RenderingIntent::Perceptual));

    let render = image
        .render_frame(0)
        .map_err(|e| format!("render JXL frame: {e}"))?;
    let mut stream = render.stream_no_alpha();
    let width = stream.width();
    let height = stream.height();
    let channels = stream.channels();
    if width == 0 || height == 0 || channels == 0 {
        return Err("decoded JXL image has no pixels".to_string());
    }

    let samples_len = (width as usize)
        .checked_mul(height as usize)
        .and_then(|n| n.checked_mul(channels as usize))
        .ok_or_else(|| "decoded JXL dimensions overflow".to_string())?;
    let mut samples = vec![0u8; samples_len];
    let written = stream.write_to_buffer::<u8>(&mut samples);
    if written != samples_len {
        return Err(format!(
            "short JXL decode: wrote {written} of {samples_len} samples"
        ));
    }

    let rgb = to_rgb(&samples, width, height, channels)?;
    let (out_w, out_h, out_rgb) = if width > MAX_PREVIEW_EDGE || height > MAX_PREVIEW_EDGE {
        let (scaled_w, scaled_h) = scaled_dimensions(width, height);
        (
            scaled_w,
            scaled_h,
            downscale_nearest(&rgb, width, height, scaled_w, scaled_h),
        )
    } else {
        (width, height, rgb)
    };

    let mut jpeg = Vec::new();
    let encoder = Encoder::new(&mut jpeg, JPEG_QUALITY);
    encoder
        .encode(&out_rgb, out_w as u16, out_h as u16, ColorType::Rgb)
        .map_err(|e| format!("encode JPEG: {e}"))?;
    Ok(jpeg)
}

/// Convert the decoded interleaved samples to 8-bit RGB.
///
/// `stream_no_alpha()` drops alpha but keeps a black channel, so 4 channels
/// means CMYK. Previews are normally 3-channel RGB; grayscale is expanded.
fn to_rgb(samples: &[u8], width: u32, height: u32, channels: u32) -> Result<Vec<u8>, String> {
    let pixels = (width as usize)
        .checked_mul(height as usize)
        .ok_or_else(|| "decoded JXL dimensions overflow".to_string())?;

    match channels {
        3 => Ok(samples[..pixels * 3].to_vec()),
        1 => {
            let mut rgb = Vec::with_capacity(pixels * 3);
            for &gray in &samples[..pixels] {
                rgb.extend_from_slice(&[gray, gray, gray]);
            }
            Ok(rgb)
        }
        4 => {
            let mut rgb = Vec::with_capacity(pixels * 3);
            for pixel in samples[..pixels * 4].chunks_exact(4) {
                let k = pixel[3] as u16;
                for &channel in &pixel[..3] {
                    let c = channel as u16;
                    rgb.push(255u16.saturating_sub((c + k).min(255)) as u8);
                }
            }
            Ok(rgb)
        }
        other => Err(format!("unsupported JXL channel count {other}")),
    }
}

/// Scale dimensions so the long edge is `MAX_PREVIEW_EDGE`, preserving aspect
/// ratio and never enlarging a small image.
fn scaled_dimensions(width: u32, height: u32) -> (u32, u32) {
    if width >= height {
        let scaled = ((height as u64 * MAX_PREVIEW_EDGE as u64) / width as u64).max(1);
        (MAX_PREVIEW_EDGE, scaled as u32)
    } else {
        let scaled = ((width as u64 * MAX_PREVIEW_EDGE as u64) / height as u64).max(1);
        (scaled as u32, MAX_PREVIEW_EDGE)
    }
}

/// Nearest-neighbour downscale of an interleaved RGB buffer. Preview quality is
/// dominated by the JXL decode; the resize only has to be fast and predictable.
fn downscale_nearest(src: &[u8], src_w: u32, src_h: u32, dst_w: u32, dst_h: u32) -> Vec<u8> {
    let mut out = vec![0u8; dst_w as usize * dst_h as usize * 3];
    for y in 0..dst_h {
        let sy = (y as u64 * src_h as u64 / dst_h as u64) as usize;
        for x in 0..dst_w {
            let sx = (x as u64 * src_w as u64 / dst_w as u64) as usize;
            let src_idx = (sy * src_w as usize + sx) * 3;
            let dst_idx = (y as usize * dst_w as usize + x as usize) * 3;
            out[dst_idx..dst_idx + 3].copy_from_slice(&src[src_idx..src_idx + 3]);
        }
    }
    out
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
