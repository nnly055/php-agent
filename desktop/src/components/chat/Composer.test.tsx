import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { Composer } from "./Composer";
import { useAppStore } from "../../store/useAppStore";
import { useUiStore } from "../../store/useUiStore";
import { MAX_IMAGES, fileToAttachment } from "../../lib/imageFile";

// jsdom 无 canvas/createImageBitmap：mock 掉预处理模块，fileToAttachment 返回固定附件。
// previewUrl 按文件名唯一：组件以 previewUrl 为缩略图 key，重名会互相吞掉（生产环境 object URL 天然唯一）
vi.mock("../../lib/imageFile", () => ({
  MAX_IMAGES: 5,
  fileToAttachment: vi.fn(async (f: File) => ({
    mediaType: "image/png",
    data: "AAAA",
    previewUrl: `data:image/png;base64,AAAA-${f.name}`,
  })),
}));

// Task 14 起后端以对象序列化模型（ModelEntry）：mock 同步为 { name } 形式
const payload = {
  profiles: [
    { id: "p-1", name: "DS", provider: "openai", apiKey: "sk-a", baseUrl: "", models: [{ name: "deepseek-chat" }, { name: "deepseek-reasoner" }] },
    { id: "p-2", name: "GLM", provider: "openai", apiKey: "sk-b", baseUrl: "", models: [{ name: "glm-4.7" }] },
  ],
  defaultProfile: "p-1",
  defaultModel: "deepseek-chat",
};

describe("Composer IME guard", () => {
  it("does not send on Enter while IME composing", () => {
    useAppStore.getState().reset();
    const onSend = vi.fn();
    render(<Composer onSend={onSend} onAbort={() => {}} />);
    const ta = screen.getByPlaceholderText(/输入任务/);
    fireEvent.change(ta, { target: { value: "nihao" } });
    fireEvent.keyDown(ta, { key: "Enter", isComposing: true });
    expect(onSend).not.toHaveBeenCalled();
  });
  it("sends on plain Enter", () => {
    useAppStore.getState().reset();
    const onSend = vi.fn();
    render(<Composer onSend={onSend} onAbort={() => {}} />);
    const ta = screen.getByPlaceholderText(/输入任务/);
    fireEvent.change(ta, { target: { value: "hello" } });
    fireEvent.keyDown(ta, { key: "Enter" });
    expect(onSend).toHaveBeenCalledWith("hello", []);
  });
});

describe("Composer 平台/模型切换器", () => {
  beforeEach(() => {
    useUiStore.getState().setProfilesPayload(null);
    useAppStore.getState().reset();
  });

  it("渲染平台/模型选择器，改变即写入 store", () => {
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    // 默认平台 = 全局默认档 p-1；模型下拉 = 该平台的模型列表
    const selects = screen.getAllByRole("combobox");
    expect(selects).toHaveLength(2);
    expect(selects[0]).toHaveValue("p-1");
    expect(screen.getByRole("option", { name: "deepseek-chat" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "deepseek-reasoner" })).toBeInTheDocument();
    // 切平台 → selection 更新且模型回落新平台首个
    fireEvent.change(selects[0], { target: { value: "p-2" } });
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-2", model: "glm-4.7" });
    // 模型下拉随平台切换：现在是 glm-4.7，p-1 的模型不再出现
    expect(screen.getByRole("option", { name: "glm-4.7" })).toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "deepseek-chat" })).not.toBeInTheDocument();
    // 切回 p-1 再选 reasoner，验证 model 通道
    fireEvent.change(selects[0], { target: { value: "p-1" } });
    fireEvent.change(selects[1], { target: { value: "deepseek-reasoner" } });
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-1", model: "deepseek-reasoner" });
  });

  it("目录为空数组时不渲染选择器", () => {
    useUiStore.getState().setProfilesPayload({ profiles: [], defaultProfile: "", defaultModel: "" });
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    expect(screen.queryAllByRole("combobox")).toHaveLength(0);
  });

  it("目录为 null 时也不渲染选择器", () => {
    useUiStore.getState().setProfilesPayload(null);
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    expect(screen.queryAllByRole("combobox")).toHaveLength(0);
  });

  it("当前模型不在列表时插入临时 option，select 不渲染为空白", () => {
    // 模拟恢复会话携带的自定义模型（目录里已不存在该模型名）
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    useAppStore.getState().setSelection({ profileId: "p-1", model: "custom-x" });
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    expect(screen.getAllByRole("combobox")[1]).toHaveValue("custom-x");
    expect(screen.getByRole("option", { name: "custom-x" })).toBeInTheDocument();
  });

  it("selection 为空时回落所选平台首个模型，不使用全局 defaultModel（显示=实际）", () => {
    // defaultModel ≠ 平台首个模型：服务端从不使用 defaultModel，显示它就是谎报
    useUiStore.getState().setProfilesPayload({ ...payload, defaultModel: "phantom-model" });
    useAppStore.getState().setActiveSession("web-1", []);
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    expect(screen.getAllByRole("combobox")[1]).toHaveValue("deepseek-chat");
    expect(screen.queryByRole("option", { name: "phantom-model" })).not.toBeInTheDocument();
  });
});

describe("Composer 图片附件", () => {
  const pngFile = () => new File(["x"], "a.png", { type: "image/png" });

  beforeEach(() => {
    useUiStore.getState().setProfilesPayload(null);
    useAppStore.getState().reset();
  });

  it("附件按钮选图后出现缩略图，可删除，Enter 发送携带 images", async () => {
    const onSend = vi.fn();
    render(<Composer onSend={onSend} onAbort={vi.fn()} />);
    const input = screen.getByLabelText("添加图片");
    fireEvent.change(input, { target: { files: [pngFile()] } });
    // 缩略图出现（fileToAttachment 异步）
    expect(await screen.findByRole("img")).toHaveAttribute("src", "data:image/png;base64,AAAA-a.png");
    // 点删除按钮 → 缩略图消失
    fireEvent.click(screen.getByLabelText("移除图片"));
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
    // 删除后无文本无附件，Enter 不发送
    fireEvent.keyDown(screen.getByPlaceholderText(/输入任务/), { key: "Enter" });
    expect(onSend).not.toHaveBeenCalled();
    // 再次选图后 Enter 发送，携带 images 数组（previewUrl 不上送）
    fireEvent.change(input, { target: { files: [pngFile()] } });
    await screen.findByRole("img");
    fireEvent.keyDown(screen.getByPlaceholderText(/输入任务/), { key: "Enter" });
    expect(onSend).toHaveBeenCalledTimes(1);
    expect(onSend).toHaveBeenCalledWith("", [{ mediaType: "image/png", data: "AAAA" }]);
    // 发送后清空附件
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });

  it("粘贴图片文件进预览，非图片 MIME 被忽略", async () => {
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    const ta = screen.getByPlaceholderText(/输入任务/);
    const textFile = new File(["y"], "a.txt", { type: "text/plain" });
    fireEvent.paste(ta, { clipboardData: { files: [pngFile(), textFile] } });
    const thumbs = await screen.findAllByRole("img");
    expect(thumbs).toHaveLength(1);
    expect(thumbs[0]).toHaveAttribute("src", "data:image/png;base64,AAAA-a.png");
  });

  it("纯图可发送（文本为空）", async () => {
    const onSend = vi.fn();
    render(<Composer onSend={onSend} onAbort={vi.fn()} />);
    fireEvent.change(screen.getByLabelText("添加图片"), { target: { files: [pngFile()] } });
    await screen.findByRole("img");
    const sendBtn = screen.getByTitle("发送");
    expect(sendBtn).not.toBeDisabled();
    fireEvent.click(sendBtn);
    expect(onSend).toHaveBeenCalledTimes(1);
    expect(onSend).toHaveBeenCalledWith("", [{ mediaType: "image/png", data: "AAAA" }]);
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });

  it("超限提示：一次选 MAX_IMAGES+2 张，超出部分被忽略并 setError「已忽略 N 张」，缩略图不超配额", async () => {
    const setError = vi.spyOn(useAppStore.getState(), "setError");
    setError.mockClear();
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    const files = Array.from({ length: MAX_IMAGES + 2 }, (_, i) => new File(["x"], `a${i}.png`, { type: "image/png" }));
    fireEvent.change(screen.getByLabelText("添加图片"), { target: { files } });
    // 只保留 MAX_IMAGES=5 张缩略图（plan Task 9 Step 1：超 5 张路径）；逐个预处理是异步的，等全部落地
    await waitFor(() => expect(screen.getAllByRole("img")).toHaveLength(MAX_IMAGES));
    expect(setError).toHaveBeenCalledWith(`最多添加 ${MAX_IMAGES} 张图片，已忽略 2 张`);
  });

  it("预处理失败降级：fileToAttachment 拒绝 → setError 且不加缩略图，后续好文件仍可添加", async () => {
    const setError = vi.spyOn(useAppStore.getState(), "setError");
    setError.mockClear();
    vi.mocked(fileToAttachment).mockRejectedValueOnce(new Error("图片解码失败"));
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    const input = screen.getByLabelText("添加图片");
    fireEvent.change(input, { target: { files: [pngFile()] } });
    await waitFor(() => expect(setError).toHaveBeenCalledWith("图片解码失败"));
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
    // 降级不中断功能：随后的正常文件照常出缩略图
    fireEvent.change(input, { target: { files: [pngFile()] } });
    expect(await screen.findByRole("img")).toHaveAttribute("src", "data:image/png;base64,AAAA-a.png");
  });
});