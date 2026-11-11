import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";

export function Markdown({ text }: { text: string }) {
  return (
    <div className="prose-agent text-sm leading-relaxed">
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          // react-markdown 对块级代码默认包一层 <pre>，而下方 code 渲染器
          // 自带样式化 <pre> → 透传外层 pre，避免无效的 pre>pre>code 嵌套。
          pre: ({ children }) => <>{children}</>,
          // react-markdown v9：ExtraProps.node 会混入剩余 props，展开到 DOM 元素
          // 会触发 TS2322；将 node 解构剔除（_node 前缀豁免 noUnusedLocals）。
          code({ className, children, node: _node, ...props }) {
            const isBlock = /language-/.test(className ?? "");
            return isBlock ? (
              <pre className="my-2 overflow-x-auto rounded-lg border border-border bg-bg p-3 font-mono text-[12.5px]">
                <code className={className} {...props}>{children}</code>
              </pre>
            ) : (
              <code className="rounded bg-elevated px-1.5 py-0.5 font-mono text-[12.5px] text-primary" {...props}>{children}</code>
            );
          },
          a({ children, node: _node, ...props }) {
            // 安全属性放在 spread 之后，防止被 props 覆盖
            return <a className="text-info underline-offset-2 hover:underline" {...props} target="_blank" rel="noreferrer">{children}</a>;
          },
        }}
      >
        {text}
      </ReactMarkdown>
    </div>
  );
}