/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./src/admin-chat/components/AgentSelector.jsx"
/*!*****************************************************!*\
  !*** ./src/admin-chat/components/AgentSelector.jsx ***!
  \*****************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ AgentSelector)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../store */ "./src/admin-chat/store/index.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__);
/**
 * Agent selector.
 *
 * Dropdown that lists published agentmod_agent posts and lets the user switch
 * which agent the chat session uses. Fetches the agent list from the REST API
 * on first render and dispatches selectAgent() on change.
 */






function AgentSelector() {
  const {
    fetchAgents,
    selectAgent
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_2__.useDispatch)(_store__WEBPACK_IMPORTED_MODULE_4__.STORE_NAME);

  // Local fetch state so the selector's spinner reflects *its own* agent
  // load, not the shared chat isLoading flag (which message sends also set).
  const [fetching, setFetching] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    agents,
    selectedAgentId,
    loading
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_2__.useSelect)(select => {
    const storeSelect = select(_store__WEBPACK_IMPORTED_MODULE_4__.STORE_NAME);
    return {
      agents: storeSelect.getAgents(),
      selectedAgentId: storeSelect.getSelectedAgentId(),
      loading: storeSelect.isLoading()
    };
  }, []);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    if (0 === agents.length) {
      setFetching(true);
      Promise.resolve(fetchAgents()).finally(() => setFetching(false));
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  if (0 === agents.length) {
    return fetching ? "" : null;
  }
  const options = [{
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('— Default Agent —', 'agent-mod'),
    value: ''
  }, ...agents.map(agent => ({
    label: agent.name,
    value: String(agent.id)
  }))];
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
    className: "agent-mod-chat__agent-selector",
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.SelectControl, {
      label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Agent', 'agent-mod'),
      hideLabelFromVision: true,
      value: selectedAgentId ? String(selectedAgentId) : '',
      options: options,
      onChange: value => selectAgent(value ? parseInt(value, 10) : null),
      disabled: loading,
      __nextHasNoMarginBottom: true
    })
  });
}

/***/ },

/***/ "./src/admin-chat/components/AttachmentUploader.jsx"
/*!**********************************************************!*\
  !*** ./src/admin-chat/components/AttachmentUploader.jsx ***!
  \**********************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__);
/**
 * Attachment uploader.
 *
 * Controlled component that handles file selection, validation, and preview.
 * Renders only the attachment preview list and the hidden file input.
 * The trigger button lives in the parent (Composer) via the exposed open() ref method.
 *
 * Props:
 *   attachments  {Array}    Current attachment list managed by the parent.
 *   onChange     {Function} Called with the new attachments array on change.
 *   disabled     {boolean}  Disables remove buttons when true.
 *
 * Ref methods:
 *   open()  Opens the native file picker.
 */




/**
 * Reads a File into a base64 data URI.
 *
 * @param {File} file The file to read.
 * @return {Promise<string>} Resolves with the data URI.
 */

function readAsDataUri(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}
let nextId = 0;
const AttachmentUploader = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.forwardRef)(function AttachmentUploader({
  attachments = [],
  onChange,
  disabled = false
}, ref) {
  const fileInputRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const config = window.agentModChat || {};
  const strings = config.strings || {};
  const limits = config.attachments || {};
  const maxBytes = limits.maxBytes || 5242880;
  const maxCount = limits.maxCount || 5;
  const mimeTypes = limits.mimeTypes || [];
  const accept = mimeTypes.join(',');
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useImperativeHandle)(ref, () => ({
    open() {
      if (fileInputRef.current) {
        fileInputRef.current.click();
      }
    }
  }));
  const onFilesSelected = async event => {
    const selected = Array.from(event.target.files || []);
    event.target.value = '';
    if (0 === selected.length) {
      return;
    }
    let room = maxCount - attachments.length;
    const accepted = [];
    for (const file of selected) {
      if (room <= 0) {
        break;
      }
      if (mimeTypes.length && !mimeTypes.includes(file.type)) {
        continue;
      }
      if (file.size > maxBytes) {
        continue;
      }

      // eslint-disable-next-line no-await-in-loop
      const data = await readAsDataUri(file);
      accepted.push({
        id: nextId++,
        name: file.name,
        mimeType: file.type,
        size: file.size,
        isImage: file.type.startsWith('image/'),
        data
      });
      room--;
    }
    if (accepted.length && onChange) {
      onChange([...attachments, ...accepted]);
    }
  };
  const removeAttachment = id => {
    if (onChange) {
      onChange(attachments.filter(item => item.id !== id));
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.Fragment, {
    children: [0 < attachments.length && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("ul", {
      className: "agent-mod-chat__attachments",
      children: attachments.map(item => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("li", {
        className: "agent-mod-chat__attachment",
        children: [item.isImage ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("img", {
          className: "agent-mod-chat__attachment-thumb",
          src: item.data,
          alt: item.name
        }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("span", {
          className: "agent-mod-chat__attachment-icon dashicons dashicons-media-default",
          "aria-hidden": "true"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("span", {
          className: "agent-mod-chat__attachment-name",
          title: item.name,
          children: item.name
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
          className: "agent-mod-chat__attachment-remove",
          icon: "no-alt",
          label: strings.removeAttachment || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Remove attachment', 'agent-mod'),
          size: "small",
          onClick: () => removeAttachment(item.id),
          disabled: disabled
        })]
      }, item.id))
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("input", {
      ref: fileInputRef,
      className: "agent-mod-chat__file-input",
      type: "file",
      multiple: true,
      accept: accept,
      onChange: onFilesSelected
    })]
  });
});
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (AttachmentUploader);

/***/ },

/***/ "./src/admin-chat/components/ChatApp.jsx"
/*!***********************************************!*\
  !*** ./src/admin-chat/components/ChatApp.jsx ***!
  \***********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ ChatApp)
/* harmony export */ });
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../store */ "./src/admin-chat/store/index.js");
/* harmony import */ var _ChatModal__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./ChatModal */ "./src/admin-chat/components/ChatModal.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__);
/**
 * Root component. Renders the modal only while the chat is open.
 */




function ChatApp() {
  const isOpen = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_0__.useSelect)(select => select(_store__WEBPACK_IMPORTED_MODULE_1__.STORE_NAME).isChatOpen(), []);
  if (!isOpen) {
    return null;
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_ChatModal__WEBPACK_IMPORTED_MODULE_2__["default"], {});
}

/***/ },

/***/ "./src/admin-chat/components/ChatModal.jsx"
/*!*************************************************!*\
  !*** ./src/admin-chat/components/ChatModal.jsx ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ ChatModal)
/* harmony export */ });
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../store */ "./src/admin-chat/store/index.js");
/* harmony import */ var _ChatPanel__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./ChatPanel */ "./src/admin-chat/components/ChatPanel.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__);
/**
 * Modal presentation wrapper around the reusable <ChatPanel/>.
 *
 * This component is responsible only for the modal chrome; the conversation
 * lives in <ChatPanel/> so the exact same UI can be reused outside a modal.
 */






function ChatModal() {
  const {
    closeChat
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_1__.useDispatch)(_store__WEBPACK_IMPORTED_MODULE_3__.STORE_NAME);
  const strings = (window.agentModChat || {}).strings || {};
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Modal, {
    title: strings.title || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('AgentMod Assistant', 'agent-mod'),
    onRequestClose: closeChat,
    className: "agent-mod-chat__modal",
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_ChatPanel__WEBPACK_IMPORTED_MODULE_4__["default"], {})
  });
}

/***/ },

/***/ "./src/admin-chat/components/ChatPanel.jsx"
/*!*************************************************!*\
  !*** ./src/admin-chat/components/ChatPanel.jsx ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ ChatPanel)
/* harmony export */ });
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../store */ "./src/admin-chat/store/index.js");
/* harmony import */ var _ConfirmationModal__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./ConfirmationModal */ "./src/admin-chat/components/ConfirmationModal.jsx");
/* harmony import */ var _MessageList__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./MessageList */ "./src/admin-chat/components/MessageList.jsx");
/* harmony import */ var _Composer__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./Composer */ "./src/admin-chat/components/Composer.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__);
/**
 * Presentation-agnostic chat panel.
 *
 * Contains only the conversation itself: error notice, message list, and
 * composer. It carries no chrome of its own (no modal, no sidebar frame), so it
 * can be dropped into a modal, a full admin page, a sidebar, or any other
 * container as-is — plug-and-play, no extra wiring required.
 */







function ChatPanel({
  className = ''
}) {
  const {
    clearError
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_1__.useDispatch)(_store__WEBPACK_IMPORTED_MODULE_2__.STORE_NAME);
  const {
    error
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_1__.useSelect)(select => {
    const storeSelect = select(_store__WEBPACK_IMPORTED_MODULE_2__.STORE_NAME);
    return {
      error: storeSelect.getError()
    };
  }, []);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.SlotFillProvider, {
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: `agent-mod-chat__body ${className}`.trim(),
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Slot, {
        name: "AgentModChatHeader"
      }), error && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Notice, {
        status: "error",
        isDismissible: true,
        onRemove: clearError,
        children: error
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_ConfirmationModal__WEBPACK_IMPORTED_MODULE_3__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_MessageList__WEBPACK_IMPORTED_MODULE_4__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_Composer__WEBPACK_IMPORTED_MODULE_5__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Slot, {
        name: "AgentModComposerToolbar"
      })]
    })
  });
}

/***/ },

/***/ "./src/admin-chat/components/Composer.jsx"
/*!************************************************!*\
  !*** ./src/admin-chat/components/Composer.jsx ***!
  \************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ Composer)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../store */ "./src/admin-chat/store/index.js");
/* harmony import */ var _AttachmentUploader__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./AttachmentUploader */ "./src/admin-chat/components/AttachmentUploader.jsx");
/* harmony import */ var _AgentSelector__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./AgentSelector */ "./src/admin-chat/components/AgentSelector.jsx");
/* harmony import */ var _ProviderModelSelector__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./ProviderModelSelector */ "./src/admin-chat/components/ProviderModelSelector.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__);
/**
 * Message composer.
 *
 * Textarea + site-context toggle + send button. File attachment handling is
 * delegated to AttachmentUploader. Enter sends, Shift+Enter inserts a newline.
 */









function Composer() {
  const [text, setText] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [attachments, setAttachments] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const uploaderRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const {
    sendMessage,
    setSiteContext,
    clearMessages,
    setConversationId
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_1__.useDispatch)(_store__WEBPACK_IMPORTED_MODULE_4__.STORE_NAME);
  const {
    loading,
    isSiteContext,
    hasMessages
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_1__.useSelect)(select => {
    const storeSelect = select(_store__WEBPACK_IMPORTED_MODULE_4__.STORE_NAME);
    return {
      loading: storeSelect.isLoading(),
      isSiteContext: storeSelect.isSiteContextEnabled(),
      hasMessages: storeSelect.getMessages().length > 0
    };
  }, []);
  const config = window.agentModChat || {};
  const strings = config.strings || {};
  const maxCount = (config.attachments || {}).maxCount || 5;
  const canSend = ('' !== text.trim() || 0 < attachments.length) && !loading;
  const submit = () => {
    if (!canSend) {
      return;
    }
    sendMessage(text, attachments);
    setText('');
    setAttachments([]);
  };
  const onKeyDown = event => {
    if ('Enter' === event.key && !event.shiftKey) {
      event.preventDefault();
      submit();
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
    className: "agent-mod-chat__composer",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_AttachmentUploader__WEBPACK_IMPORTED_MODULE_5__["default"], {
      ref: uploaderRef,
      attachments: attachments,
      onChange: setAttachments,
      disabled: loading
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("div", {
      className: "agent-mod-chat__context-scope",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.ToggleControl, {
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Site Context (RAG)', 'agent-mod'),
        help: isSiteContext ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Agent reads your site data.', 'agent-mod') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('General knowledge only.', 'agent-mod'),
        checked: isSiteContext,
        onChange: setSiteContext,
        __nextHasNoMarginBottom: true
      })
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.TextareaControl, {
      className: "agent-mod-chat__input",
      value: text,
      onChange: setText,
      onKeyDown: onKeyDown,
      placeholder: strings.placeholder || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Type your message…', 'agent-mod'),
      rows: 2,
      disabled: loading,
      __nextHasNoMarginBottom: true
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "agent-mod-chat__composer-actions",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
        className: "agent-mod-chat__tools",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_AgentSelector__WEBPACK_IMPORTED_MODULE_6__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_ProviderModelSelector__WEBPACK_IMPORTED_MODULE_7__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
          icon: "paperclip",
          label: strings.attach || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Attach files', 'agent-mod'),
          onClick: () => uploaderRef.current?.open(),
          disabled: loading || attachments.length >= maxCount
        }), hasMessages && !loading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
          variant: "tertiary",
          size: "small",
          onClick: () => {
            clearMessages();
            setConversationId(null);
          },
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('New Topic', 'agent-mod')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Button, {
        variant: "primary",
        onClick: submit,
        disabled: !canSend,
        children: strings.send || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Send', 'agent-mod')
      })]
    })]
  });
}

/***/ },

/***/ "./src/admin-chat/components/ConfirmationModal.jsx"
/*!*********************************************************!*\
  !*** ./src/admin-chat/components/ConfirmationModal.jsx ***!
  \*********************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ ConfirmationModal)
/* harmony export */ });
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../store */ "./src/admin-chat/store/index.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);
/**
 * Write-action confirmation modal.
 *
 * Renders a @wordpress/components Modal when the store has a pending
 * write action. The user can approve or cancel the operation.
 * Approval dispatches confirmAction(); cancellation dispatches clearConfirmation().
 */





function ConfirmationModal() {
  const {
    confirmAction,
    clearConfirmation,
    setError
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_1__.useDispatch)(_store__WEBPACK_IMPORTED_MODULE_3__.STORE_NAME);
  const {
    pendingConfirmation,
    conversationId
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_1__.useSelect)(select => {
    const storeSelect = select(_store__WEBPACK_IMPORTED_MODULE_3__.STORE_NAME);
    return {
      pendingConfirmation: storeSelect.getPendingConfirmation(),
      conversationId: storeSelect.getConversationId()
    };
  }, []);
  if (!pendingConfirmation) {
    return null;
  }
  const {
    token,
    actionName,
    args
  } = pendingConfirmation;
  const onConfirm = () => {
    confirmAction(token, conversationId);
  };
  const onCancel = () => {
    clearConfirmation();
    setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Action cancelled.', 'agent-mod'));
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Modal, {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Confirm Action', 'agent-mod'),
    onRequestClose: onCancel,
    className: "agent-mod-chat__confirm-modal",
    isDismissible: false,
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('The agent wants to perform the following action. Do you confirm?', 'agent-mod')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "agent-mod-chat__confirm-action",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("strong", {
        children: actionName
      }), 0 < Object.keys(args).length && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("pre", {
        className: "agent-mod-chat__confirm-args",
        children: JSON.stringify(args, null, 2)
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "agent-mod-chat__confirm-buttons",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Button, {
        variant: "primary",
        onClick: onConfirm,
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Confirm', 'agent-mod')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.Button, {
        variant: "secondary",
        onClick: onCancel,
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Cancel', 'agent-mod')
      })]
    })]
  });
}

/***/ },

/***/ "./src/admin-chat/components/MessageItem.jsx"
/*!***************************************************!*\
  !*** ./src/admin-chat/components/MessageItem.jsx ***!
  \***************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ MessageItem)
/* harmony export */ });
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__);

/**
 * A single chat message bubble, styled by role (user/assistant).
 *
 * Renders any attachments above the text: images as thumbnails, other files as
 * labelled chips. A message with attachments but no text shows no empty bubble.
 */
function MessageItem({
  message
}) {
  const role = 'assistant' === message.role ? 'assistant' : 'user';
  const attachments = message.attachments || [];
  const hasText = '' !== (message.text || '');
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("div", {
    className: `agent-mod-chat__message agent-mod-chat__message--${role}`,
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsxs)("div", {
      className: "agent-mod-chat__message-content",
      children: [0 < attachments.length && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("ul", {
        className: "agent-mod-chat__message-attachments",
        children: attachments.map((item, index) => item.isImage || (item.mimeType || '').startsWith('image/') ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("li", {
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("img", {
            className: "agent-mod-chat__message-image",
            src: item.data,
            alt: item.name || ''
          })
        }, index) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsxs)("li", {
          className: "agent-mod-chat__message-file",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("span", {
            className: "agent-mod-chat__message-file-icon dashicons dashicons-media-default",
            "aria-hidden": "true"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("span", {
            children: item.name || ''
          })]
        }, index))
      }), hasText && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("div", {
        className: "agent-mod-chat__bubble",
        children: message.text
      })]
    })
  });
}

/***/ },

/***/ "./src/admin-chat/components/MessageList.jsx"
/*!***************************************************!*\
  !*** ./src/admin-chat/components/MessageList.jsx ***!
  \***************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ MessageList)
/* harmony export */ });
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../store */ "./src/admin-chat/store/index.js");
/* harmony import */ var _MessageItem__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./MessageItem */ "./src/admin-chat/components/MessageItem.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__);
/**
 * Renders the list of chat messages and auto-scrolls to the latest one.
 */







function MessageList() {
  const {
    messages,
    loading
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_0__.useSelect)(select => {
    const storeSelect = select(_store__WEBPACK_IMPORTED_MODULE_4__.STORE_NAME);
    return {
      messages: storeSelect.getMessages(),
      loading: storeSelect.isLoading()
    };
  }, []);
  const endRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useRef)(null);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useEffect)(() => {
    if (endRef.current) {
      endRef.current.scrollIntoView({
        behavior: 'smooth'
      });
    }
  }, [messages.length, loading]);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
    className: "agent-mod-chat__messages",
    children: [0 === messages.length && !loading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "agent-mod-chat__empty",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Ask the assistant anything about your site.', 'agent-mod')
    }), messages.map((message, index) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_MessageItem__WEBPACK_IMPORTED_MODULE_5__["default"], {
      message: message
    }, index)), loading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "agent-mod-chat__loading",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_2__.Spinner, {})
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      ref: endRef
    })]
  });
}

/***/ },

/***/ "./src/admin-chat/components/ProviderModelSelector.jsx"
/*!*************************************************************!*\
  !*** ./src/admin-chat/components/ProviderModelSelector.jsx ***!
  \*************************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ ProviderModelSelector)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../store */ "./src/admin-chat/store/index.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__);
/**
 * Provider + model picker (drop-up).
 *
 * Lists the connected AI providers (from agentModChat.providers) in a menu that
 * opens upward. Selecting a provider lazily loads its text-generation models and
 * shows them in a side column; picking a model stores the provider+model pair,
 * which sendMessage forwards so the WP AI Client uses exactly that model.
 *
 * An "Auto" entry clears the selection and lets the AI Client auto-select.
 */






function ProviderModelSelector() {
  const config = window.agentModChat || {};
  const providers = Array.isArray(config.providers) ? config.providers : [];
  const {
    fetchProviderModels,
    selectProviderModel
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_2__.useDispatch)(_store__WEBPACK_IMPORTED_MODULE_4__.STORE_NAME);
  const {
    selectedProvider,
    selectedModel
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_2__.useSelect)(select => {
    const storeSelect = select(_store__WEBPACK_IMPORTED_MODULE_4__.STORE_NAME);
    return {
      selectedProvider: storeSelect.getSelectedProvider(),
      selectedModel: storeSelect.getSelectedModel()
    };
  }, []);

  // Which provider's models are shown in the right-hand column.
  const [activeProvider, setActiveProvider] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const {
    models,
    loading
  } = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_2__.useSelect)(select => {
    const storeSelect = select(_store__WEBPACK_IMPORTED_MODULE_4__.STORE_NAME);
    return {
      models: activeProvider ? storeSelect.getProviderModels(activeProvider) : null,
      loading: activeProvider === storeSelect.getModelsLoading()
    };
  }, [activeProvider]);

  // Warm the cache in the background as soon as the panel mounts, so the picker
  // shows models instantly (no spinner) when the user opens it. Providers whose
  // models are already cached (in the store or hydrated from localStorage) are a
  // no-op inside the thunk, so this is cheap to run on every open.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    providers.forEach(provider => fetchProviderModels(provider.id));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // No provider connected: link to the Connectors settings screen.
  if (0 === providers.length) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("a", {
      className: "agent-mod-chat__provider agent-mod-chat__provider--empty",
      href: config.connectorsUrl || '#',
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('No AI provider connected. Click to configure.', 'agent-mod'),
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
        className: "dashicons dashicons-warning",
        "aria-hidden": "true"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('No provider', 'agent-mod')
      })]
    });
  }
  const openProvider = id => {
    setActiveProvider(id);
    fetchProviderModels(id);
  };
  const current = providers.find(provider => provider.id === selectedProvider);
  const toggleLabel = current ? selectedModel ? `${current.name}: ${selectedModel}` : current.name : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Auto model', 'agent-mod');
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Dropdown, {
    className: "agent-mod-chat__provider-picker",
    popoverProps: {
      placement: 'top-start'
    },
    renderToggle: ({
      isOpen,
      onToggle
    }) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
      variant: "tertiary",
      size: "small",
      icon: "superhero-alt",
      "aria-expanded": isOpen,
      onClick: () => {
        if (!isOpen) {
          openProvider(selectedProvider || providers[0].id);
        }
        onToggle();
      },
      children: toggleLabel
    }),
    renderContent: ({
      onClose
    }) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "agent-mod-chat__provider-menu",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("ul", {
        className: "agent-mod-chat__provider-list",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("li", {
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
            className: !selectedProvider ? 'is-selected' : '',
            onClick: () => {
              selectProviderModel(null, null);
              onClose();
            },
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Auto (default)', 'agent-mod')
          })
        }), providers.map(provider => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("li", {
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
            className: [activeProvider === provider.id ? 'is-active' : '', selectedProvider === provider.id ? 'is-selected' : ''].join(' ').trim(),
            onClick: () => openProvider(provider.id),
            children: [provider.logoUrl && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("img", {
              className: "agent-mod-chat__provider-logo",
              src: provider.logoUrl,
              alt: ""
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
              className: "agent-mod-chat__provider-name",
              children: provider.name
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
              className: "dashicons dashicons-arrow-right-alt2",
              "aria-hidden": "true"
            })]
          })
        }, provider.id))]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
        className: "agent-mod-chat__model-list",
        children: [!activeProvider && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("p", {
          className: "agent-mod-chat__model-hint",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Select a provider to see its models.', 'agent-mod')
        }), activeProvider && !loading && Array.isArray(models) && 0 === models.length && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("p", {
          className: "agent-mod-chat__model-hint",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('No models found.', 'agent-mod')
        }), activeProvider && Array.isArray(models) && models.map(model => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
          className: selectedProvider === activeProvider && selectedModel === model.id ? 'is-selected' : '',
          onClick: () => {
            selectProviderModel(activeProvider, model.id);
            onClose();
          },
          children: model.name || model.id
        }, model.id))]
      })]
    })
  });
}

/***/ },

/***/ "./src/admin-chat/index.js"
/*!*********************************!*\
  !*** ./src/admin-chat/index.js ***!
  \*********************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _store__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./store */ "./src/admin-chat/store/index.js");
/* harmony import */ var _components_ChatApp__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./components/ChatApp */ "./src/admin-chat/components/ChatApp.jsx");
/* harmony import */ var _components_ChatPanel__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./components/ChatPanel */ "./src/admin-chat/components/ChatPanel.jsx");
/* harmony import */ var _components_Composer__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./components/Composer */ "./src/admin-chat/components/Composer.jsx");
/* harmony import */ var _components_MessageList__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./components/MessageList */ "./src/admin-chat/components/MessageList.jsx");
/* harmony import */ var _components_ConfirmationModal__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./components/ConfirmationModal */ "./src/admin-chat/components/ConfirmationModal.jsx");
/* harmony import */ var _style_scss__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./style.scss */ "./src/admin-chat/style.scss");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__);
/**
 * Admin-chat widget entry.
 *
 * Mounts the chat into any container, plug-and-play. The conversation UI lives
 * in the presentation-agnostic <ChatPanel/>; this entry only decides *where* and
 * *in which wrapper* it renders:
 *
 *  - The admin-footer root (`#agent-mod-chat-root`) renders the modal variant
 *    and is opened from the admin bar (current behaviour).
 *  - Any element carrying `data-agent-mod-chat="inline|modal"` is mounted with
 *    the requested variant, so the chat can be dropped into a page, a sidebar,
 *    a meta box, etc. without writing any extra code.
 */










/**
 * Available presentation variants.
 *
 * - `modal`: opens in a <Modal/>, toggled through the store (admin bar).
 * - `inline`: renders the panel right where it is placed.
 */

const VARIANTS = {
  modal: _components_ChatApp__WEBPACK_IMPORTED_MODULE_3__["default"],
  inline: _components_ChatPanel__WEBPACK_IMPORTED_MODULE_4__["default"]
};

/**
 * Renders a chat variant into the given node.
 *
 * @param {HTMLElement} node    Target container.
 * @param {string}      variant Variant key from VARIANTS; falls back to inline.
 */
function mount(node, variant) {
  const Component = VARIANTS[variant] || _components_ChatPanel__WEBPACK_IMPORTED_MODULE_4__["default"];
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createRoot)(node).render(/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(Component, {}));
}

/**
 * Mounts every chat container found on the page.
 */
function mountApp() {
  // Backwards-compatible admin-footer modal root.
  const footer = document.getElementById('agent-mod-chat-root');
  if (footer && !footer.dataset.agentModChat) {
    mount(footer, 'modal');
  }

  // Plug-and-play containers anywhere on the page.
  document.querySelectorAll('[data-agent-mod-chat]').forEach(node => mount(node, node.dataset.agentModChat));
}

/**
 * Binds the admin bar link to open the chat modal.
 */
function bindToolbar() {
  const link = document.querySelector('#wp-admin-bar-agent-mod-chat a');
  if (!link) {
    return;
  }
  link.addEventListener('click', event => {
    event.preventDefault();
    (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_1__.dispatch)(_store__WEBPACK_IMPORTED_MODULE_2__.STORE_NAME).openChat();
  });
}
document.addEventListener('DOMContentLoaded', () => {
  mountApp();
  bindToolbar();
});

// Public API — exposes components and store name for agent-mod-pro and third-party
// plugins. Import with `import { ChatPanel } from '@agent-mod/components'` (after
// configuring the webpack external) or access via `window.agentMod` directly.
window.agentMod = window.agentMod || {};
Object.assign(window.agentMod, {
  ChatPanel: _components_ChatPanel__WEBPACK_IMPORTED_MODULE_4__["default"],
  Composer: _components_Composer__WEBPACK_IMPORTED_MODULE_5__["default"],
  MessageList: _components_MessageList__WEBPACK_IMPORTED_MODULE_6__["default"],
  ConfirmationModal: _components_ConfirmationModal__WEBPACK_IMPORTED_MODULE_7__["default"],
  storeName: _store__WEBPACK_IMPORTED_MODULE_2__.STORE_NAME
});

/***/ },

/***/ "./src/admin-chat/store/actions.js"
/*!*****************************************!*\
  !*** ./src/admin-chat/store/actions.js ***!
  \*****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   appendMessage: () => (/* binding */ appendMessage),
/* harmony export */   clearConfirmation: () => (/* binding */ clearConfirmation),
/* harmony export */   clearError: () => (/* binding */ clearError),
/* harmony export */   clearMessages: () => (/* binding */ clearMessages),
/* harmony export */   closeChat: () => (/* binding */ closeChat),
/* harmony export */   confirmAction: () => (/* binding */ confirmAction),
/* harmony export */   fetchAgents: () => (/* binding */ fetchAgents),
/* harmony export */   fetchProviderModels: () => (/* binding */ fetchProviderModels),
/* harmony export */   openChat: () => (/* binding */ openChat),
/* harmony export */   selectAgent: () => (/* binding */ selectAgent),
/* harmony export */   selectProviderModel: () => (/* binding */ selectProviderModel),
/* harmony export */   sendMessage: () => (/* binding */ sendMessage),
/* harmony export */   setAgents: () => (/* binding */ setAgents),
/* harmony export */   setConversationId: () => (/* binding */ setConversationId),
/* harmony export */   setError: () => (/* binding */ setError),
/* harmony export */   setLoading: () => (/* binding */ setLoading),
/* harmony export */   setModelsLoading: () => (/* binding */ setModelsLoading),
/* harmony export */   setPendingConfirmation: () => (/* binding */ setPendingConfirmation),
/* harmony export */   setProviderModels: () => (/* binding */ setProviderModels),
/* harmony export */   setSiteContext: () => (/* binding */ setSiteContext)
/* harmony export */ });
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/api-fetch */ "@wordpress/api-fetch");
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/hooks */ "@wordpress/hooks");
/* harmony import */ var _wordpress_hooks__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/**
 * Actions for the admin-chat store.
 *
 * Includes plain action creators and the `sendMessage` thunk which talks to the
 * REST chat endpoint via apiFetch. Thunks are enabled by default in
 * @wordpress/data stores.
 */



function openChat() {
  return {
    type: 'OPEN_CHAT'
  };
}
function closeChat() {
  return {
    type: 'CLOSE_CHAT'
  };
}
function appendMessage(message) {
  return {
    type: 'APPEND_MESSAGE',
    message
  };
}
function setLoading(isLoading) {
  return {
    type: 'SET_LOADING',
    isLoading
  };
}
function setError(error) {
  return {
    type: 'SET_ERROR',
    error
  };
}
function clearError() {
  return {
    type: 'CLEAR_ERROR'
  };
}
function clearMessages() {
  (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.doAction)('agent_mod.new_conversation');
  return {
    type: 'CLEAR_MESSAGES'
  };
}
function setSiteContext(enabled) {
  return {
    type: 'SET_SITE_CONTEXT',
    enabled
  };
}
function setConversationId(conversationId) {
  return {
    type: 'SET_CONVERSATION_ID',
    conversationId
  };
}
function setAgents(agents) {
  return {
    type: 'SET_AGENTS',
    agents
  };
}
function selectAgent(agentId) {
  return {
    type: 'SELECT_AGENT',
    agentId
  };
}
function setPendingConfirmation(data) {
  (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.doAction)('agent_mod.confirmation_requested', data);
  return {
    type: 'SET_PENDING_CONFIRMATION',
    data
  };
}
function setProviderModels(providerId, models) {
  return {
    type: 'SET_PROVIDER_MODELS',
    providerId,
    models
  };
}
function setModelsLoading(providerId) {
  return {
    type: 'SET_MODELS_LOADING',
    providerId
  };
}

/**
 * Selects a provider + model for the next chat requests. Pass ( null, null ) to
 * clear the selection and let the AI Client auto-select.
 *
 * @param {string|null} provider Provider id.
 * @param {string|null} model    Model id.
 */
function selectProviderModel(provider, model) {
  return {
    type: 'SELECT_PROVIDER_MODEL',
    provider,
    model
  };
}

/**
 * Lazily fetches the text-generation models for a provider and caches them in
 * the store. No-op when the models are already loaded.
 *
 * @param {string} providerId Provider id.
 */
const fetchProviderModels = providerId => async ({
  dispatch,
  select
}) => {
  if (!providerId || null !== select.getProviderModels(providerId)) {
    return;
  }
  dispatch.setModelsLoading(providerId);
  try {
    const config = window.agentModChat || {};
    const models = await _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: (config.restNamespace || 'agent-mod/v1') + '/provider-models?provider=' + encodeURIComponent(providerId)
    });
    dispatch.setProviderModels(providerId, Array.isArray(models) ? models : []);
  } catch {
    dispatch.setProviderModels(providerId, []);
  } finally {
    dispatch.setModelsLoading(null);
  }
};
function clearConfirmation() {
  return {
    type: 'CLEAR_CONFIRMATION'
  };
}

/**
 * Fetches the list of agents from the REST endpoint and updates the store.
 */
const fetchAgents = () => async ({
  dispatch
}) => {
  try {
    const config = window.agentModChat || {};
    const agents = await _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: (config.restNamespace || 'agent-mod/v1') + '/agents'
    });
    if (Array.isArray(agents)) {
      dispatch.setAgents(agents);
    }
  } catch {
    // Silently ignore — agents list is optional
  }
};

/**
 * Executes a confirmed write action via the confirm-action REST endpoint.
 *
 * @param {string} token          Confirmation token.
 * @param {number} conversationId Current conversation ID.
 */
const confirmAction = (token, conversationId) => async ({
  dispatch
}) => {
  dispatch.setLoading(true);
  try {
    const config = window.agentModChat || {};
    const data = await _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: (config.restNamespace || 'agent-mod/v1') + '/confirm-action',
      method: 'POST',
      data: {
        token,
        conversationId
      }
    });
    dispatch.clearConfirmation();
    if (data && data.success && !data.pendingConfirmation) {
      dispatch.appendMessage({
        role: 'assistant',
        text: data.text || ''
      });
      if (data.conversationId) {
        dispatch.setConversationId(data.conversationId);
      }
    } else if (data && data.pendingConfirmation) {
      dispatch.setPendingConfirmation({
        token: data.confirmationToken,
        actionName: data.pendingAction?.name || '',
        args: data.pendingAction?.args || {},
        pendingToolCalls: data.pendingToolCalls || []
      });
    } else {
      const message = data?.error?.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('An unexpected error occurred.', 'agent-mod');
      dispatch.setError(message);
    }
  } catch (err) {
    dispatch.clearConfirmation();
    dispatch.setError(err && err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Request failed. Please try again.', 'agent-mod'));
  } finally {
    dispatch.setLoading(false);
  }
};

/**
 * Maps a stored attachment to the minimal shape sent to the REST endpoint.
 *
 * @param {Object} attachment Stored attachment.
 * @return {Object} Wire-shape attachment.
 */
function toWireAttachment({
  name,
  mimeType,
  data
}) {
  return {
    name,
    mimeType,
    data
  };
}

/**
 * Sends a user message to the chat endpoint and appends the assistant reply.
 *
 * @param {string} text          The user message.
 * @param {Array}  [attachments] Attachments for this turn (each has name, mimeType, data).
 */
const sendMessage = (text, attachments = []) => async ({
  dispatch,
  select
}) => {
  const trimmed = (text || '').trim();
  const files = Array.isArray(attachments) ? attachments : [];
  if ('' === trimmed && 0 === files.length) {
    return;
  }
  const config = window.agentModChat || {};

  // Build history from the messages present *before* this new turn.
  const history = select.getMessages().map(({
    role,
    text: messageText,
    attachments: turnFiles
  }) => ({
    role,
    text: messageText,
    attachments: (turnFiles || []).map(toWireAttachment)
  }));

  // Use the selected agent config if available; fall back to defaultAgent.
  const selectedAgentId = select.getSelectedAgentId();
  const agents = select.getAgents();
  const selectedAgent = selectedAgentId ? agents.find(a => a.id === selectedAgentId) : null;
  const agent = {
    ...(config.defaultAgent || {}),
    ...(selectedAgent || {}),
    autoIncludeSiteContext: select.isSiteContextEnabled()
  };

  // A provider/model picked in the picker overrides the agent defaults so the
  // WP AI Client uses exactly that provider and model for this request.
  const pickedProvider = select.getSelectedProvider();
  if (pickedProvider) {
    agent.provider = pickedProvider;
    agent.model = select.getSelectedModel() || null;
  }
  dispatch.clearError();
  dispatch.appendMessage({
    role: 'user',
    text: trimmed,
    attachments: files
  });
  dispatch.setLoading(true);
  try {
    const basePayload = {
      message: trimmed,
      agent,
      history,
      attachments: files.map(toWireAttachment),
      conversationId: select.getConversationId()
    };
    const payload = (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.applyFilters)('agent_mod.send_message_payload', basePayload);
    const rawData = await _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
      path: config.restPath,
      method: 'POST',
      data: payload
    });
    const data = (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.applyFilters)('agent_mod.receive_message_response', rawData);
    if (data && data.success) {
      (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_1__.doAction)('agent_mod.after_message_sent', data, agent);
      if (data.pendingConfirmation) {
        // A write action needs user confirmation before executing.
        dispatch.setPendingConfirmation({
          token: data.confirmationToken,
          actionName: data.pendingAction?.name || '',
          args: data.pendingAction?.args || {},
          pendingToolCalls: data.pendingToolCalls || []
        });
      } else {
        dispatch.appendMessage({
          role: 'assistant',
          text: data.text || ''
        });
        if (data.conversationId) {
          dispatch.setConversationId(data.conversationId);
        }
      }
    } else {
      const message = data && data.error && data.error.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('An unexpected error occurred.', 'agent-mod');
      dispatch.setError(message);
    }
  } catch (err) {
    const message = err && err.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Request failed. Please try again.', 'agent-mod');
    dispatch.setError(message);
  } finally {
    dispatch.setLoading(false);
  }
};

/***/ },

/***/ "./src/admin-chat/store/index.js"
/*!***************************************!*\
  !*** ./src/admin-chat/store/index.js ***!
  \***************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   STORE_NAME: () => (/* binding */ STORE_NAME),
/* harmony export */   store: () => (/* binding */ store)
/* harmony export */ });
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/data */ "@wordpress/data");
/* harmony import */ var _wordpress_data__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_data__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _reducer__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./reducer */ "./src/admin-chat/store/reducer.js");
/* harmony import */ var _actions__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./actions */ "./src/admin-chat/store/actions.js");
/* harmony import */ var _selectors__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./selectors */ "./src/admin-chat/store/selectors.js");
/* harmony import */ var _persistence__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./persistence */ "./src/admin-chat/store/persistence.js");
/**
 * Admin-chat @wordpress/data store registration.
 */





const STORE_NAME = 'agent-mod/chat';
const store = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_0__.createReduxStore)(STORE_NAME, {
  reducer: _reducer__WEBPACK_IMPORTED_MODULE_1__["default"],
  actions: _actions__WEBPACK_IMPORTED_MODULE_2__,
  selectors: _selectors__WEBPACK_IMPORTED_MODULE_3__
});
(0,_wordpress_data__WEBPACK_IMPORTED_MODULE_0__.register)(store);

// Persist the provider model lists to localStorage whenever they change, so the
// picker is populated instantly on the next page load. The reference check keeps
// this to one write per actual change (the reducer returns a new map object only
// on SET_PROVIDER_MODELS), even though subscribe fires on every store update.
let lastModelsMap = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_0__.select)(STORE_NAME).getProviderModelsMap();
(0,_wordpress_data__WEBPACK_IMPORTED_MODULE_0__.subscribe)(() => {
  const modelsMap = (0,_wordpress_data__WEBPACK_IMPORTED_MODULE_0__.select)(STORE_NAME).getProviderModelsMap();
  if (modelsMap !== lastModelsMap) {
    lastModelsMap = modelsMap;
    (0,_persistence__WEBPACK_IMPORTED_MODULE_4__.saveProviderModels)(modelsMap);
  }
});

/***/ },

/***/ "./src/admin-chat/store/persistence.js"
/*!*********************************************!*\
  !*** ./src/admin-chat/store/persistence.js ***!
  \*********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   loadProviderModels: () => (/* binding */ loadProviderModels),
/* harmony export */   saveProviderModels: () => (/* binding */ saveProviderModels)
/* harmony export */ });
/**
 * Lightweight localStorage persistence for the provider model lists.
 *
 * The model lists rarely change, so caching them on the client means the
 * provider/model picker shows instantly on every admin page load — no network
 * round-trip and no loading state. The TTL matches the server-side transient
 * (6 hours) so the cache refreshes on roughly the same cadence; expired or
 * malformed data is ignored, letting the background prefetch repopulate it.
 */

const STORAGE_KEY = 'agentModChatProviderModels';
const TTL_MS = 6 * 60 * 60 * 1000; // 6 hours, matches the server transient.

/**
 * Reads the cached provider models, keyed by provider id.
 *
 * @return {Object} Map of providerId -> [{ id, name }], or an empty object when
 *                  there is no usable (present, non-expired, valid) cache.
 */
function loadProviderModels() {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return {};
    }
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') {
      return {};
    }
    if (!parsed.ts || Date.now() - parsed.ts > TTL_MS) {
      return {};
    }
    return parsed.models && typeof parsed.models === 'object' ? parsed.models : {};
  } catch {
    return {};
  }
}

/**
 * Persists the provider models map. Best-effort: storage errors (quota, private
 * mode, unavailable) are swallowed since the cache is purely an optimization.
 *
 * @param {Object} models Map of providerId -> [{ id, name }].
 */
function saveProviderModels(models) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
      ts: Date.now(),
      models
    }));
  } catch {
    // Ignore — the in-memory store still works without persistence.
  }
}

/***/ },

/***/ "./src/admin-chat/store/reducer.js"
/*!*****************************************!*\
  !*** ./src/admin-chat/store/reducer.js ***!
  \*****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ reducer)
/* harmony export */ });
/* harmony import */ var _persistence__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./persistence */ "./src/admin-chat/store/persistence.js");
/**
 * Pure reducer for the admin-chat store.
 */

const DEFAULT_STATE = {
  isOpen: false,
  messages: [],
  isLoading: false,
  error: null,
  isSiteContextEnabled: true,
  conversationId: null,
  agents: [],
  selectedAgentId: null,
  pendingConfirmation: null,
  // { token, actionName, args, pendingToolCalls }
  selectedProvider: null,
  // provider id chosen in the provider/model picker
  selectedModel: null,
  // model id chosen for the selected provider
  // providerId -> [{ id, name }]. Hydrated from localStorage so the picker is
  // populated instantly across page loads; refreshed by the background prefetch.
  providerModels: (0,_persistence__WEBPACK_IMPORTED_MODULE_0__.loadProviderModels)(),
  modelsLoading: null // providerId currently being fetched, or null
};
function reducer(state = DEFAULT_STATE, action) {
  switch (action.type) {
    case 'OPEN_CHAT':
      return {
        ...state,
        isOpen: true
      };
    case 'CLOSE_CHAT':
      return {
        ...state,
        isOpen: false
      };
    case 'APPEND_MESSAGE':
      return {
        ...state,
        messages: [...state.messages, action.message]
      };
    case 'SET_LOADING':
      return {
        ...state,
        isLoading: action.isLoading
      };
    case 'SET_ERROR':
      return {
        ...state,
        error: action.error,
        isLoading: false
      };
    case 'CLEAR_ERROR':
      return {
        ...state,
        error: null
      };
    case 'CLEAR_MESSAGES':
      return {
        ...state,
        messages: [],
        error: null
      };
    case 'SET_SITE_CONTEXT':
      return {
        ...state,
        isSiteContextEnabled: action.enabled
      };
    case 'SET_CONVERSATION_ID':
      return {
        ...state,
        conversationId: action.conversationId
      };
    case 'SET_AGENTS':
      return {
        ...state,
        agents: action.agents
      };
    case 'SELECT_AGENT':
      return {
        ...state,
        selectedAgentId: action.agentId
      };
    case 'SET_PROVIDER_MODELS':
      return {
        ...state,
        providerModels: {
          ...state.providerModels,
          [action.providerId]: action.models
        }
      };
    case 'SET_MODELS_LOADING':
      return {
        ...state,
        modelsLoading: action.providerId
      };
    case 'SELECT_PROVIDER_MODEL':
      return {
        ...state,
        selectedProvider: action.provider,
        selectedModel: action.model
      };
    case 'SET_PENDING_CONFIRMATION':
      return {
        ...state,
        pendingConfirmation: action.data,
        isLoading: false
      };
    case 'CLEAR_CONFIRMATION':
      return {
        ...state,
        pendingConfirmation: null
      };
    default:
      return state;
  }
}

/***/ },

/***/ "./src/admin-chat/store/selectors.js"
/*!*******************************************!*\
  !*** ./src/admin-chat/store/selectors.js ***!
  \*******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   getAgents: () => (/* binding */ getAgents),
/* harmony export */   getConversationId: () => (/* binding */ getConversationId),
/* harmony export */   getError: () => (/* binding */ getError),
/* harmony export */   getMessages: () => (/* binding */ getMessages),
/* harmony export */   getModelsLoading: () => (/* binding */ getModelsLoading),
/* harmony export */   getPendingConfirmation: () => (/* binding */ getPendingConfirmation),
/* harmony export */   getProviderModels: () => (/* binding */ getProviderModels),
/* harmony export */   getProviderModelsMap: () => (/* binding */ getProviderModelsMap),
/* harmony export */   getSelectedAgentId: () => (/* binding */ getSelectedAgentId),
/* harmony export */   getSelectedModel: () => (/* binding */ getSelectedModel),
/* harmony export */   getSelectedProvider: () => (/* binding */ getSelectedProvider),
/* harmony export */   isChatOpen: () => (/* binding */ isChatOpen),
/* harmony export */   isLoading: () => (/* binding */ isLoading),
/* harmony export */   isSiteContextEnabled: () => (/* binding */ isSiteContextEnabled)
/* harmony export */ });
/**
 * Selectors for the admin-chat store.
 */

function getMessages(state) {
  return state.messages;
}
function isLoading(state) {
  return state.isLoading;
}
function isChatOpen(state) {
  return state.isOpen;
}
function getError(state) {
  return state.error;
}
function isSiteContextEnabled(state) {
  return state.isSiteContextEnabled;
}
function getConversationId(state) {
  return state.conversationId;
}
function getAgents(state) {
  return state.agents;
}
function getSelectedAgentId(state) {
  return state.selectedAgentId;
}
function getPendingConfirmation(state) {
  return state.pendingConfirmation;
}
function getSelectedProvider(state) {
  return state.selectedProvider;
}
function getSelectedModel(state) {
  return state.selectedModel;
}
function getProviderModels(state, providerId) {
  return state.providerModels[providerId] || null;
}
function getProviderModelsMap(state) {
  return state.providerModels;
}
function getModelsLoading(state) {
  return state.modelsLoading;
}

/***/ },

/***/ "./src/admin-chat/style.scss"
/*!***********************************!*\
  !*** ./src/admin-chat/style.scss ***!
  \***********************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ },

/***/ "react/jsx-runtime"
/*!**********************************!*\
  !*** external "ReactJSXRuntime" ***!
  \**********************************/
(module) {

module.exports = window["ReactJSXRuntime"];

/***/ },

/***/ "@wordpress/api-fetch"
/*!**********************************!*\
  !*** external ["wp","apiFetch"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["apiFetch"];

/***/ },

/***/ "@wordpress/components"
/*!************************************!*\
  !*** external ["wp","components"] ***!
  \************************************/
(module) {

module.exports = window["wp"]["components"];

/***/ },

/***/ "@wordpress/data"
/*!******************************!*\
  !*** external ["wp","data"] ***!
  \******************************/
(module) {

module.exports = window["wp"]["data"];

/***/ },

/***/ "@wordpress/element"
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
(module) {

module.exports = window["wp"]["element"];

/***/ },

/***/ "@wordpress/hooks"
/*!*******************************!*\
  !*** external ["wp","hooks"] ***!
  \*******************************/
(module) {

module.exports = window["wp"]["hooks"];

/***/ },

/***/ "@wordpress/i18n"
/*!******************************!*\
  !*** external ["wp","i18n"] ***!
  \******************************/
(module) {

module.exports = window["wp"]["i18n"];

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		if (!(moduleId in __webpack_modules__)) {
/******/ 			delete __webpack_module_cache__[moduleId];
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = __webpack_modules__;
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/chunk loaded */
/******/ 	(() => {
/******/ 		var deferred = [];
/******/ 		__webpack_require__.O = (result, chunkIds, fn, priority) => {
/******/ 			if(chunkIds) {
/******/ 				priority = priority || 0;
/******/ 				for(var i = deferred.length; i > 0 && deferred[i - 1][2] > priority; i--) deferred[i] = deferred[i - 1];
/******/ 				deferred[i] = [chunkIds, fn, priority];
/******/ 				return;
/******/ 			}
/******/ 			var notFulfilled = Infinity;
/******/ 			for (var i = 0; i < deferred.length; i++) {
/******/ 				var [chunkIds, fn, priority] = deferred[i];
/******/ 				var fulfilled = true;
/******/ 				for (var j = 0; j < chunkIds.length; j++) {
/******/ 					if ((priority & 1 === 0 || notFulfilled >= priority) && Object.keys(__webpack_require__.O).every((key) => (__webpack_require__.O[key](chunkIds[j])))) {
/******/ 						chunkIds.splice(j--, 1);
/******/ 					} else {
/******/ 						fulfilled = false;
/******/ 						if(priority < notFulfilled) notFulfilled = priority;
/******/ 					}
/******/ 				}
/******/ 				if(fulfilled) {
/******/ 					deferred.splice(i--, 1)
/******/ 					var r = fn();
/******/ 					if (r !== undefined) result = r;
/******/ 				}
/******/ 			}
/******/ 			return result;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/jsonp chunk loading */
/******/ 	(() => {
/******/ 		// no baseURI
/******/ 		
/******/ 		// object to store loaded and loading chunks
/******/ 		// undefined = chunk not loaded, null = chunk preloaded/prefetched
/******/ 		// [resolve, reject, Promise] = chunk loading, 0 = chunk loaded
/******/ 		var installedChunks = {
/******/ 			"admin-chat/index": 0,
/******/ 			"admin-chat/style-index": 0
/******/ 		};
/******/ 		
/******/ 		// no chunk on demand loading
/******/ 		
/******/ 		// no prefetching
/******/ 		
/******/ 		// no preloaded
/******/ 		
/******/ 		// no HMR
/******/ 		
/******/ 		// no HMR manifest
/******/ 		
/******/ 		__webpack_require__.O.j = (chunkId) => (installedChunks[chunkId] === 0);
/******/ 		
/******/ 		// install a JSONP callback for chunk loading
/******/ 		var webpackJsonpCallback = (parentChunkLoadingFunction, data) => {
/******/ 			var [chunkIds, moreModules, runtime] = data;
/******/ 			// add "moreModules" to the modules object,
/******/ 			// then flag all "chunkIds" as loaded and fire callback
/******/ 			var moduleId, chunkId, i = 0;
/******/ 			if(chunkIds.some((id) => (installedChunks[id] !== 0))) {
/******/ 				for(moduleId in moreModules) {
/******/ 					if(__webpack_require__.o(moreModules, moduleId)) {
/******/ 						__webpack_require__.m[moduleId] = moreModules[moduleId];
/******/ 					}
/******/ 				}
/******/ 				if(runtime) var result = runtime(__webpack_require__);
/******/ 			}
/******/ 			if(parentChunkLoadingFunction) parentChunkLoadingFunction(data);
/******/ 			for(;i < chunkIds.length; i++) {
/******/ 				chunkId = chunkIds[i];
/******/ 				if(__webpack_require__.o(installedChunks, chunkId) && installedChunks[chunkId]) {
/******/ 					installedChunks[chunkId][0]();
/******/ 				}
/******/ 				installedChunks[chunkId] = 0;
/******/ 			}
/******/ 			return __webpack_require__.O(result);
/******/ 		}
/******/ 		
/******/ 		var chunkLoadingGlobal = globalThis["webpackChunkagent_mod"] = globalThis["webpackChunkagent_mod"] || [];
/******/ 		chunkLoadingGlobal.forEach(webpackJsonpCallback.bind(null, 0));
/******/ 		chunkLoadingGlobal.push = webpackJsonpCallback.bind(null, chunkLoadingGlobal.push.bind(chunkLoadingGlobal));
/******/ 	})();
/******/ 	
/************************************************************************/
/******/ 	
/******/ 	// startup
/******/ 	// Load entry module and return exports
/******/ 	// This entry module depends on other loaded chunks and execution need to be delayed
/******/ 	var __webpack_exports__ = __webpack_require__.O(undefined, ["admin-chat/style-index"], () => (__webpack_require__("./src/admin-chat/index.js")))
/******/ 	__webpack_exports__ = __webpack_require__.O(__webpack_exports__);
/******/ 	
/******/ })()
;
//# sourceMappingURL=index.js.map