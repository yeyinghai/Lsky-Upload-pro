/**
 * LskyProUpload 粘贴图片上传功能
 * 在 Typecho 编辑器中，粘贴图片时直接上传到兰空图床
 */

// 防止脚本重复加载
if (window.__LskyProUploadLoaded) {
  throw new Error('LskyProUpload script already loaded');
}
window.__LskyProUploadLoaded = true;

(function() {
  'use strict';

  // 获取编辑器元素
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
      for (let ta of textareas) {
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
    if (lastDotIndex > 0) {
      return filename.substring(0, lastDotIndex);
    }
    return filename;
  };

  /**
   * 显示输入对话框
   */
  const showInputDialog = (defaultName) => {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 99999;
      `;

      const dialog = document.createElement('div');
      dialog.style.cssText = `
        background: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        max-width: 400px;
        width: 90%;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      `;

      dialog.innerHTML = `
        <div style="margin-bottom: 20px;">
          <h3 style="margin: 0 0 10px 0; font-size: 18px; color: #333;">请输入图片名称</h3>
          <p style="margin: 0; color: #999; font-size: 12px;">不包含扩展名，直接回车使用默认名称</p>
        </div>
        <input
          type="text"
          id="lsky-image-name"
          value="${defaultName}"
          style="
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            margin-bottom: 15px;
          "
          autofocus
        />
        <div style="display: flex; gap: 10px; justify-content: flex-end;">
          <button id="lsky-cancel-btn" style="
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: #f5f5f5;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
          ">取消</button>
          <button id="lsky-confirm-btn" style="
            padding: 8px 16px;
            border: none;
            background: #1890ff;
            color: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
          ">确认</button>
        </div>
      `;

      overlay.appendChild(dialog);
      document.body.appendChild(overlay);

      const input = dialog.querySelector('#lsky-image-name');
      const confirmBtn = dialog.querySelector('#lsky-confirm-btn');
      const cancelBtn = dialog.querySelector('#lsky-cancel-btn');

      const cleanup = () => {
        document.body.removeChild(overlay);
      };

      const confirm = () => {
        const name = input.value.trim() || defaultName;
        cleanup();
        resolve(name);
      };

      confirmBtn.addEventListener('click', () => {
        confirm();
      });
      cancelBtn.addEventListener('click', () => {
        cleanup();
        resolve(null);
      });

      // Enter 键确认
      input.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
          confirm();
        }
      });

      input.focus();
      input.select();
    });
  };

  /**
   * 显示进度条
   */
  const showProgress = () => {
    const progressContainer = document.createElement('div');
    progressContainer.id = 'lsky-upload-progress';
    progressContainer.style.cssText = `
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
      z-index: 999999;
      min-width: 300px;
      text-align: center;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    `;

    progressContainer.innerHTML = `
      <p style="margin: 0 0 15px 0; color: #333; font-size: 14px; font-weight: bold;">正在上传图片...</p>
      <div style="
        width: 100%;
        height: 8px;
        background: #f0f0f0;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 10px;
      ">
        <div id="lsky-progress-bar" style="
          height: 100%;
          width: 0%;
          background: linear-gradient(90deg, #1890ff, #52c41a);
          transition: width 0.3s ease;
        "></div>
      </div>
      <p id="lsky-progress-text" style="margin: 0; color: #999; font-size: 12px;">0%</p>
    `;

    document.body.appendChild(progressContainer);

    return {
      update: (percent) => {
        const bar = document.querySelector('#lsky-progress-bar');
        const text = document.querySelector('#lsky-progress-text');
        if (bar) bar.style.width = percent + '%';
        if (text) text.textContent = percent + '%';
      },
      close: () => {
        const container = document.querySelector('#lsky-upload-progress');
        if (container) document.body.removeChild(container);
      }
    };
  };

  /**
   * 显示提示消息
   */
  const showToast = (message, type = 'success') => {
    const toast = document.createElement('div');
    toast.style.cssText = `
      position: fixed;
      top: 50px;
      right: 20px;
      background: ${type === 'success' ? '#52c41a' : '#f5222d'};
      color: white;
      padding: 12px 20px;
      border-radius: 4px;
      font-size: 14px;
      z-index: 999999;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      animation: slideIn 0.3s ease;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
      if (document.body.contains(toast)) {
        document.body.removeChild(toast);
      }
    }, 3000);
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

    // 判断编辑器类型：textarea 还是 contenteditable
    const isTextarea = editor.tagName === 'TEXTAREA';
    let cursorPos = 0;

    if (isTextarea) {
      cursorPos = editor.selectionStart;
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('name', imageName);
    formData.append('action', 'lsky_paste_upload');

    const progress = showProgress();

    try {
      const xhr = new XMLHttpRequest();

      // 监听上传进度
      xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
          const percentComplete = Math.round((e.loaded / e.total) * 100);
          progress.update(percentComplete);
        }
      });

      // 返回 Promise
      const response = await new Promise((resolve, reject) => {
        xhr.onload = () => {
          if (xhr.status === 200) {
            try {
              const data = JSON.parse(xhr.responseText);
              resolve(data);
            } catch (e) {
              console.error('[LskyProUpload] JSON 解析失败:', e);
              reject(new Error('响应格式错误: ' + xhr.responseText));
            }
          } else {
            reject(new Error('HTTP ' + xhr.status));
          }
        };

        xhr.onerror = () => {
          console.error('[LskyProUpload] 网络错误');
          reject(new Error('网络错误'));
        };

        xhr.onabort = () => {
          console.error('[LskyProUpload] 请求被中止');
          reject(new Error('请求被中止'));
        };

        // 构建上传 URL
        const uploadUrl = new URL(window.location.href);
        uploadUrl.search = '';
        uploadUrl.searchParams.set('action', 'lsky_paste_upload');

        xhr.open('POST', uploadUrl.toString(), true);
        xhr.send(formData);
      });

      progress.close();

      if (response.status) {
        // 插入 Markdown 到编辑器
        const markdown = response.data.markdown;

        if (isTextarea) {
          // Textarea 编辑器
          const text = editor.value;
          const newText = text.slice(0, cursorPos) + markdown + text.slice(cursorPos);
          editor.value = newText;
          editor.focus();
          editor.setSelectionRange(cursorPos + markdown.length, cursorPos + markdown.length);
          editor.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
          // ContentEditable 编辑器
          editor.focus();

          // 如果有保存的光标范围，使用它
          if (savedRange) {
            try {
              const selection = window.getSelection();
              selection.removeAllRanges();
              selection.addRange(savedRange);
            } catch (err) {
              // 恢复光标失败，继续执行
            }
          }

          document.execCommand('insertText', false, markdown);

          // 触发事件
          editor.dispatchEvent(new Event('input', { bubbles: true }));
          editor.dispatchEvent(new Event('change', { bubbles: true }));
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
    if (!items) {
      return;
    }

    for (let i = 0; i < items.length; i++) {
      const item = items[i];

      // 检查是否为图片文件
      if (item.kind === 'file') {
        const file = item.getAsFile();

        if (isImage(file)) {
          e.preventDefault();

          const defaultName = generateDefaultName(file.name);

          // 保存光标位置/选区
          let savedRange = null;
          try {
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
              savedRange = selection.getRangeAt(0).cloneRange();
            }
          } catch (err) {
            // 保存光标失败，继续执行
          }

          // 显示输入对话框
          const imageName = await showInputDialog(defaultName);

          if (imageName) {
            await uploadImage(file, imageName, savedRange);
          }

          return;
        }
      }
    }
  };

  /**
   * 初始化
   */
  const init = () => {
    const editor = getEditor();
    if (!editor) {
      setTimeout(init, 1000);
      return;
    }

    // 在捕获阶段监听粘贴事件
    editor.addEventListener('paste', handlePaste, true);
  };

  // DOM 加载完成后初始化
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
