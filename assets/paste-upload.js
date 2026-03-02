/**
 * LskyProUpload 粘贴图片上传功能
 * 在 Typecho 编辑器中，粘贴图片时直接上传到兰空图床
 * @version 1.1.0
 */

// 防止脚本重复加载
if (window.__LskyProUploadLoaded) {
  throw new Error('LskyProUpload script already loaded');
}
window.__LskyProUploadLoaded = true;

(function () {
  'use strict';

  // 修复：限制最大重试次数，避免 init 无限循环
  let _retryCount = 0;
  const MAX_RETRIES = 10;

  /**
   * 获取编辑器元素
   */
  const getEditor = () => {
    // 优先查找 CodeMirror 编辑器（Typecho 后台使用）
    let editor = document.querySelector('.cm-content[contenteditable]');
    if (editor) return editor;

    // 查找 Joe 主题评论编辑器
    editor = document.querySelector('.joe_owo__target');
    if (editor) return editor;

    // 查找后台文章编辑器
    editor = document.querySelector('textarea[name="text"]');
    if (editor) return editor;

    // 查找最大的 textarea
    const textareas = document.querySelectorAll('textarea');
    if (textareas.length > 0) {
      let maxArea = textareas[0];
      for (const ta of textareas) {
        if (ta.offsetHeight > maxArea.offsetHeight) {
          maxArea = ta;
        }
      }
      return maxArea;
    }

    return null;
  };

  /**
   * 验证是否为图片
   */
  const isImage = (file) => {
    const imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff'];
    return imageTypes.includes(file.type) || /\.(jpg|jpeg|png|gif|webp|bmp|tiff)$/i.test(file.name);
  };

  /**
   * 生成默认图片名称（去掉扩展名）
   */
  const generateDefaultName = (filename) => {
    const lastDotIndex = filename.lastIndexOf('.');
    return lastDotIndex > 0 ? filename.substring(0, lastDotIndex) : filename;
  };

  /**
   * 显示输入对话框
   * 修复：不再使用 innerHTML 插入用户数据，避免 XSS 风险
   */
  const showInputDialog = (defaultName) => {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.style.cssText = `
        position: fixed; top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.5);
        display: flex; justify-content: center; align-items: center;
        z-index: 99999;
      `;

      const dialog = document.createElement('div');
      dialog.style.cssText = `
        background: white; padding: 30px; border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        max-width: 400px; width: 90%;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      `;

      // 修复：使用 DOM API 构建元素，避免直接拼接用户数据到 innerHTML
      const title = document.createElement('h3');
      title.style.cssText = 'margin: 0 0 10px 0; font-size: 18px; color: #333;';
      title.textContent = '请输入图片名称';

      const hint = document.createElement('p');
      hint.style.cssText = 'margin: 0 0 20px 0; color: #999; font-size: 12px;';
      hint.textContent = '不包含扩展名，直接回车使用默认名称';

      const input = document.createElement('input');
      input.type = 'text';
      // 修复：通过 .value 属性赋值而非 innerHTML，彻底防止 XSS
      input.value = defaultName;
      input.style.cssText = `
        width: 100%; padding: 10px; border: 1px solid #ddd;
        border-radius: 4px; font-size: 14px;
        box-sizing: border-box; margin-bottom: 15px;
      `;

      const btnRow = document.createElement('div');
      btnRow.style.cssText = 'display: flex; gap: 10px; justify-content: flex-end;';

      const cancelBtn = document.createElement('button');
      cancelBtn.style.cssText = `
        padding: 8px 16px; border: 1px solid #ddd;
        background: #f5f5f5; border-radius: 4px;
        cursor: pointer; font-size: 14px;
      `;
      cancelBtn.textContent = '取消';

      const confirmBtn = document.createElement('button');
      confirmBtn.style.cssText = `
        padding: 8px 16px; border: none;
        background: #1890ff; color: white;
        border-radius: 4px; cursor: pointer; font-size: 14px;
      `;
      confirmBtn.textContent = '确认';

      btnRow.appendChild(cancelBtn);
      btnRow.appendChild(confirmBtn);
      dialog.appendChild(title);
      dialog.appendChild(hint);
      dialog.appendChild(input);
      dialog.appendChild(btnRow);
      overlay.appendChild(dialog);
      document.body.appendChild(overlay);

      const cleanup = () => document.body.removeChild(overlay);

      const confirm = () => {
        const name = input.value.trim() || defaultName;
        cleanup();
        resolve(name);
      };

      confirmBtn.addEventListener('click', confirm);
      cancelBtn.addEventListener('click', () => { cleanup(); resolve(null); });
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') confirm(); });

      input.focus();
      input.select();
    });
  };

  /**
   * 显示进度条
   */
  const showProgress = () => {
    const container = document.createElement('div');
    container.id = 'lsky-upload-progress';
    container.style.cssText = `
      position: fixed; top: 50%; left: 50%;
      transform: translate(-50%, -50%);
      background: white; padding: 30px; border-radius: 8px;
      box-shadow: 0 2px 20px rgba(0,0,0,0.3);
      z-index: 999999; min-width: 300px; text-align: center;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    `;

    const label = document.createElement('p');
    label.style.cssText = 'margin: 0 0 15px 0; color: #333; font-size: 14px; font-weight: bold;';
    label.textContent = '正在上传图片...';

    const track = document.createElement('div');
    track.style.cssText = `
      width: 100%; height: 8px; background: #f0f0f0;
      border-radius: 4px; overflow: hidden; margin-bottom: 10px;
    `;

    const bar = document.createElement('div');
    bar.id = 'lsky-progress-bar';
    bar.style.cssText = `
      height: 100%; width: 0%;
      background: linear-gradient(90deg, #1890ff, #52c41a);
      transition: width 0.3s ease;
    `;

    const text = document.createElement('p');
    text.id = 'lsky-progress-text';
    text.style.cssText = 'margin: 0; color: #999; font-size: 12px;';
    text.textContent = '0%';

    track.appendChild(bar);
    container.appendChild(label);
    container.appendChild(track);
    container.appendChild(text);
    document.body.appendChild(container);

    return {
      update: (percent) => {
        bar.style.width = percent + '%';
        text.textContent = percent + '%';
      },
      close: () => {
        if (document.body.contains(container)) {
          document.body.removeChild(container);
        }
      }
    };
  };

  /**
   * 显示提示消息
   * 修复：补充 slideIn 动画定义
   */
  const showToast = (() => {
    // 仅注入一次样式
    const style = document.createElement('style');
    style.textContent = `
      @keyframes lskySlideIn {
        from { opacity: 0; transform: translateX(20px); }
        to   { opacity: 1; transform: translateX(0); }
      }
    `;
    document.head.appendChild(style);

    return (message, type = 'success') => {
      const toast = document.createElement('div');
      toast.style.cssText = `
        position: fixed; top: 50px; right: 20px;
        background: ${type === 'success' ? '#52c41a' : '#f5222d'};
        color: white; padding: 12px 20px; border-radius: 4px;
        font-size: 14px; z-index: 999999;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: lskySlideIn 0.3s ease;
      `;
      toast.textContent = message;
      document.body.appendChild(toast);
      setTimeout(() => {
        if (document.body.contains(toast)) document.body.removeChild(toast);
      }, 3000);
    };
  })();

  /**
   * 将 Markdown 插入到编辑器光标处
   * 修复：ContentEditable 使用现代 Range API 替代已废弃的 document.execCommand
   */
  const insertMarkdown = (editor, markdown, savedRange) => {
    const isTextarea = editor.tagName === 'TEXTAREA';

    if (isTextarea) {
      const start = editor.selectionStart;
      const end   = editor.selectionEnd;
      editor.value = editor.value.slice(0, start) + markdown + editor.value.slice(end);
      editor.focus();
      editor.setSelectionRange(start + markdown.length, start + markdown.length);
      editor.dispatchEvent(new Event('input', { bubbles: true }));
    } else {
      editor.focus();

      let range = null;

      // 优先恢复粘贴前保存的光标位置
      if (savedRange) {
        try {
          const sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(savedRange);
          range = savedRange;
        } catch (_) { /* 忽略恢复失败 */ }
      }

      if (!range) {
        const sel = window.getSelection();
        range = sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
      }

      if (range) {
        // 修复：使用标准 Range API 插入文本，替代废弃的 document.execCommand
        range.deleteContents();
        const textNode = document.createTextNode(markdown);
        range.insertNode(textNode);
        range.setStartAfter(textNode);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      } else {
        // 兜底：追加到末尾
        editor.textContent += markdown;
      }

      editor.dispatchEvent(new Event('input', { bubbles: true }));
      editor.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };

  /**
   * 上传图片到服务器
   */
  const uploadImage = async (file, imageName, savedRange = null) => {
    const editor = getEditor();
    if (!editor) {
      console.error('[LskyProUpload] 找不到编辑器元素');
      showToast('编辑器加载失败', 'error');
      return;
    }

    // 提前记录 textarea 光标位置（对话框弹出后光标会丢失）
    const cursorPos = editor.tagName === 'TEXTAREA' ? editor.selectionStart : 0;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('name', imageName);

    const progress = showProgress();

    try {
      const uploadUrl = new URL(window.location.href);
      uploadUrl.search = '';
      uploadUrl.searchParams.set('action', 'lsky_paste_upload');

      const xhr = new XMLHttpRequest();

      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          progress.update(Math.round((e.loaded / e.total) * 100));
        }
      });

      const response = await new Promise((resolve, reject) => {
        xhr.onload = () => {
          if (xhr.status === 200) {
            try {
              resolve(JSON.parse(xhr.responseText));
            } catch (e) {
              reject(new Error('响应格式错误: ' + xhr.responseText));
            }
          } else {
            reject(new Error('HTTP ' + xhr.status));
          }
        };
        xhr.onerror  = () => reject(new Error('网络错误'));
        xhr.onabort  = () => reject(new Error('请求被中止'));

        xhr.open('POST', uploadUrl.toString(), true);
        xhr.send(formData);
      });

      progress.close();

      if (response.status) {
        const markdown = response.data.markdown;

        // 对 textarea 使用提前记录的光标位置
        if (editor.tagName === 'TEXTAREA') {
          const text = editor.value;
          editor.value = text.slice(0, cursorPos) + markdown + text.slice(cursorPos);
          editor.focus();
          editor.setSelectionRange(cursorPos + markdown.length, cursorPos + markdown.length);
          editor.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
          insertMarkdown(editor, markdown, savedRange);
        }

        showToast('图片上传成功', 'success');
      } else {
        console.error('[LskyProUpload] 上传失败:', response.message);
        showToast(response.message || '上传失败', 'error');
      }
    } catch (error) {
      progress.close();
      console.error('[LskyProUpload] 上传出错:', error);
      showToast(error.message || '上传出错', 'error');
    }
  };

  /**
   * 处理粘贴事件
   */
  const handlePaste = async (e) => {
    const items = e.clipboardData?.items;
    if (!items) return;

    for (const item of items) {
      if (item.kind !== 'file') continue;

      const file = item.getAsFile();
      if (!file || !isImage(file)) continue;

      e.preventDefault();

      const defaultName = generateDefaultName(file.name);

      // 保存当前光标位置（弹框会导致 contenteditable 失焦）
      let savedRange = null;
      try {
        const sel = window.getSelection();
        if (sel.rangeCount > 0) {
          savedRange = sel.getRangeAt(0).cloneRange();
        }
      } catch (_) { /* 忽略 */ }

      const imageName = await showInputDialog(defaultName);
      if (imageName) {
        await uploadImage(file, imageName, savedRange);
      }

      return; // 每次粘贴只处理第一张图片
    }
  };

  /**
   * 初始化：绑定粘贴事件
   * 修复：增加最大重试次数限制，防止无限重试
   */
  const init = () => {
    if (_retryCount >= MAX_RETRIES) {
      console.warn('[LskyProUpload] 超过最大重试次数，未能找到编辑器');
      return;
    }

    const editor = getEditor();
    if (!editor) {
      _retryCount++;
      setTimeout(init, 1000);
      return;
    }

    editor.addEventListener('paste', handlePaste, true);
    console.log('[LskyProUpload] 初始化成功，已绑定到编辑器:', editor.tagName);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
