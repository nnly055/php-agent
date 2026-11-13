import type { ImageAttachment } from "./api";

/** 单条消息最多附带的图片数 */
export const MAX_IMAGES = 5;
/** 长边超过该值即等比降采样 */
export const MAX_EDGE = 2000;
/** 单图编码字节数上限 */
export const MAX_BYTES = 5 * 1024 * 1024;

// JPEG 重编码质量档：从 0.9 逐档降到 0.6，仍超 MAX_BYTES 判失败
const QUALITY_STEPS = [0.9, 0.8, 0.7, 0.6];

/** 长边 > MAX_EDGE 时返回等比缩放比（长边缩到 MAX_EDGE），否则 null（无需缩放） */
export function nextScale(w: number, h: number): number | null {
  const long = Math.max(w, h);
  if (long <= MAX_EDGE) return null;
  return MAX_EDGE / long;
}

/** 质量档序列 0.9→0.8→0.7→0.6；prev 为 null 给 0.9，耗尽返回 null 表失败 */
export function nextQuality(prev: number | null): number | null {
  if (prev === null) return QUALITY_STEPS[0];
  const idx = QUALITY_STEPS.indexOf(prev);
  return idx >= 0 && idx < QUALITY_STEPS.length - 1 ? QUALITY_STEPS[idx + 1] : null;
}

function readAsDataURL(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = () => reject(new Error("图片读取失败"));
    reader.readAsDataURL(blob);
  });
}

/** 剥掉 data URL 的 `data:...;base64,` 前缀，只留纯 base64 载荷 */
function stripDataPrefix(dataUrl: string): string {
  return dataUrl.slice(dataUrl.indexOf(",") + 1);
}

/**
 * 图片文件 → 可发送附件：长边超限先 canvas 降采样再 JPEG 重编码逐档压缩；
 * 无需缩放且原始字节不超限时原样透传（PNG/JPEG/WebP/GIF 保留原编码）。
 * previewUrl 为本地 object URL，仅供缩略图展示，发送时不上送。
 */
export async function fileToAttachment(file: File): Promise<ImageAttachment & { previewUrl: string }> {
  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch {
    throw new Error("图片解码失败，请换一张图片");
  }

  const scale = nextScale(bitmap.width, bitmap.height);
  if (scale === null && file.size <= MAX_BYTES) {
    bitmap.close();
    const data = stripDataPrefix(await readAsDataURL(file));
    return { mediaType: file.type, data, previewUrl: URL.createObjectURL(file) };
  }

  // 降采样（或无需缩放但原始字节超限时按原尺寸）画到 canvas，逐档 JPEG 重编码
  const w = scale === null ? bitmap.width : Math.max(1, Math.round(bitmap.width * scale));
  const h = scale === null ? bitmap.height : Math.max(1, Math.round(bitmap.height * scale));
  const canvas = document.createElement("canvas");
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext("2d");
  if (!ctx) {
    bitmap.close();
    throw new Error("图片处理失败");
  }
  ctx.drawImage(bitmap, 0, 0, w, h);
  bitmap.close();

  let q = nextQuality(null);
  while (q !== null) {
    const quality = q; // 快照当前档：闭包内不追踪外层 let 的窄化
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", quality));
    if (blob && blob.size <= MAX_BYTES) {
      const data = stripDataPrefix(await readAsDataURL(blob));
      return { mediaType: "image/jpeg", data, previewUrl: URL.createObjectURL(blob) };
    }
    q = nextQuality(q);
  }
  throw new Error("图片过大，请裁剪后重试");
}
