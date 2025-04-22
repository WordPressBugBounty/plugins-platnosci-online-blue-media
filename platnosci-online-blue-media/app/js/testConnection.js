jQuery(document).ready(function () {
  const $ = jQuery;

  const autopayAudit = {
    status: null,
    auditState: {
      testId: '',
      criticalTotal: 0,
      warningsTotal: 0,
      stageName: '',
      log: [],
      summary: [],
      downloadUrl: '',
    },

    dictionary: {
      status: {
        'idle': 'idle',
        'requestNew': 'requestNew',
        'requestContinue': 'requestContinue',
        'error': 'error',
        'finished': 'finished'
      },
      responseStatus: {
        'continue': 'continue',
        'finished': 'finished',
      },
      logLevel: {
        'info': 'info',
        'warning': 'warning',
        'critical': 'critical',
      },
      strings: {
        'auditInProgress': '',
        'auditCompleted': '',
        'auditAbortedDueCriticalError': '',
        'serverTest': '',
        'pleaseWait': '',
        'criticalProblemsTotal': '',
        'warningsTotal': '',
        'start': '',
        'startAgain': '',
        'criticalAjaxMessage': '',
        'criticalErrorOccurredMessage': '',
        'critical': '',
        'criticalGenericMessage': ''
      }

    },

    wpAjax: {
      adminAjaxUrl: '',
      adminAjaxActionName: '',
      autopayAction: {
        new: 'new',
        continue: 'continue',
      },
      nonce: '',
      strings: '',
      log:
        {
          request: {},
          response: {},
        }
    },


    elements: {
      buttons: {
        startBtn: null,
        downloadBtn: null,
        copyBtn: null,
      },
      statusBox: {
        wrapper: '',
        icon: '',
        title: '',
        stageName: '',
        pleaseWait: '',
        counterWarning: '',
        counterCritical: ''
      },
      log: {
        wrapper: ''
      },
      summary: {
        wrapper: '',
        success: {
          item: '',
          header: ''
        },
        error: {
          item: '',
          header: '',
          message: '',
        },
        warning: {
          item: '',
          header: '',
          message: '',
        },
      },
      hidden: {
        nonce: ''
      }
    },


    getStatus: function () {
      return this.status;
    },

    init: function () {
      try {
        if (this.status !== null) {
          return;
        }
        this.resetAuditState
        this.registerJqueryElements()
        this.initDictionary()
        this.updateUiReset()
        this.status = this.dictionary.status.idle
        this.initAjaxVars()
        this.registerEvents()
      } catch (e) {
        this.abort(e.message)
      }
    },

    initAjaxVars: function () {
      this.wpAjax.adminAjaxUrl = autopayAuditData.adminAjaxUrl
      this.wpAjax.adminAjaxActionName = autopayAuditData.adminAjaxActionName
      this.wpAjax.nonce = this.elements.hidden.nonce.val()
    },

    initDictionary: function () {
      this.dictionary.strings = autopayAuditData.strings
    },

    resetAuditState: function () {
      this.auditState.criticalTotal = 0;
      this.auditState.warningsTotal = 0;
      this.auditState.stageName = '';
      this.auditState.log = [];
      this.auditState.summary = [];
      this.auditState.downloadUrl = '';
    },

    updateStateTotal(logEntry) {
      if (logEntry.level === this.dictionary.logLevel.warning) {
        this.auditState.warningsTotal += 1
      }

      if (logEntry.level === this.dictionary.logLevel.critical) {
        this.auditState.criticalTotal += 1
      }
    },

    registerJqueryElements: function () {
      this.elements.buttons.startBtn = this.getJqueryElementByAttr('autopay_audit_btn_start', 'id');
      this.elements.buttons.downloadBtn = this.getJqueryElementByAttr('autopay_audit_btn_download', 'id');
      this.elements.buttons.copyBtn = this.getJqueryElementByAttr('autopay_audit_btn_copy', 'id');
      this.elements.hidden.nonce = this.getJqueryElementByAttr('autopay_audit_nonce', 'id');
      this.elements.statusBox.wrapper = this.getJqueryElementByAttr('autopay_audit_status', 'id');
      this.elements.statusBox.icon = this.getJqueryElementByAttr('autopay_audit_status_icon', 'id');
      this.elements.statusBox.title = this.getJqueryElementByAttr('autopay_audit_status_title', 'id');
      this.elements.statusBox.stageName = this.getJqueryElementByAttr('autopay_audit_stage_name', 'id');
      this.elements.statusBox.pleaseWait = this.getJqueryElementByAttr('autopay_audit_please_wait', 'id');
      this.elements.statusBox.counterCritical = this.getJqueryElementByAttr('autopay_audit_counter_critical', 'id');
      this.elements.statusBox.counterWarning = this.getJqueryElementByAttr('autopay_audit_counter_warning', 'id');
      this.elements.log.wrapper = this.getJqueryElementByAttr('autopay_audit_log', 'id');
      this.elements.summary.wrapper = this.getJqueryElementByAttr('autopay_audit_summary', 'id');
      this.elements.summary.success.item = this.getJqueryElementByAttr('autopay_audit_s_s', 'id');
      this.elements.summary.success.header = this.getJqueryElementByAttr('autopay_audit_s_s_h', 'id');
      this.elements.summary.warning.item = this.getJqueryElementByAttr('autopay_audit_s_w', 'id');
      this.elements.summary.warning.header = this.getJqueryElementByAttr('autopay_audit_s_w_h', 'id');
      this.elements.summary.warning.message = this.getJqueryElementByAttr('autopay_audit_s_w_m', 'id');
      this.elements.summary.error.item = this.getJqueryElementByAttr('autopay_audit_s_e', 'id');
      this.elements.summary.error.header = this.getJqueryElementByAttr('autopay_audit_s_e_h', 'id');
      this.elements.summary.error.message = this.getJqueryElementByAttr('autopay_audit_s_e_m', 'id');
    },

    updateUiStart: function () {
      this.resetAuditState()
      this.updateUiReset()
      this.elements.statusBox.icon.removeClass('completed').addClass('in-progress')
      this.updateUiDisableStartBtn()
      var show = this.elements.statusBox.wrapper
      show.show()
    },

    updateUiReset: function () {
      this.elements.buttons.startBtn.html(this.dictionary.strings.start);
      this.elements.statusBox.counterCritical.html('0');
      this.elements.statusBox.counterWarning.html('0');
      this.elements.statusBox.stageName.html('');
      this.elements.statusBox.title.html(this.dictionary.strings.auditInProgress)
      this.elements.statusBox.pleaseWait.html(this.dictionary.strings.pleaseWait)
      var cleanHtml = this.elements.log.wrapper
        .add(this.elements.summary.success.header)
        .add(this.elements.summary.warning.header)
        .add(this.elements.summary.error.header)
        .add(this.elements.summary.error.message)
        .add(this.elements.summary.warning.message)
      cleanHtml.html('');

      var displayNone = this.elements.summary.success.item
        .add(this.elements.summary.warning.item)
        .add(this.elements.summary.error.item)
        .add(this.elements.summary.success.item)
        .add(this.elements.statusBox.wrapper)
        .add(this.elements.buttons.downloadBtn)
        .add(this.elements.buttons.copyBtn)
      displayNone.hide()
      this.updateUiEnableStartBtn()
    },

    updateUiEnableStartBtn: function () {
      this.elements.buttons.startBtn.prop("disabled", false).removeClass('disabled');
    },

    updateUiDisableStartBtn: function () {
      this.elements.buttons.startBtn.prop("disabled", true).addClass('disabled')
    },

    updateUiEnableDownloadBtn: function () {
      this.elements.buttons.downloadBtn.prop("disabled", false).removeClass('disabled');
    },

    updateUiDisableDownloadBtn: function () {
      this.elements.buttons.downloadBtn.prop("disabled", true).addClass('disabled')
    },

    updateUiEnableCopyBtn: function () {
      this.elements.buttons.copyBtn.prop("disabled", false).removeClass('disabled');
    },

    updateUiDisableCopyBtn: function () {
      this.elements.buttons.copyBtn.prop("disabled", true).addClass('disabled')
    },

    updateUiStatusBoxStop: function () {
      this.elements.statusBox.title.html(this.dictionary.strings.auditAbortedDueCriticalError)
      this.elements.statusBox.pleaseWait.html('')
      this.elements.statusBox.icon.removeClass('in-progress').addClass('completed')
    },

    updateUiFinished: function (summarySuccess, summaryWarning, summaryError) {
      this.updateUiStatusBoxStop()

      if (this.isObjectValid(summarySuccess)) {
        this.updateUiShowSummarySuccess(summarySuccess.header)
      }

      if (this.isObjectValid(summaryWarning)) {
        this.updateUiShowSummaryWarning(summaryWarning.header, summaryWarning.message)
      }

      if (this.isObjectValid(summaryError)) {
        this.updateUiShowSummaryError(summaryError.header, summaryError.message)
      }
      this.updateUiEnableStartBtn()
      this.elements.buttons.copyBtn.show()

      if (this.auditState.downloadUrl !== '') {
        this.elements.buttons.downloadBtn.show()
      }

      return false
    },

    updateUiHideStatusBox: function () {
      this.elements.statusBox.wrapper.hide()
    },

    updateUiCriticalDuringProcess: function (header, message, hideStatusBox = false) {
      this.updateUiEnableStartBtn()

      if (this.auditState.downloadUrl !== '') {
        this.elements.buttons.downloadBtn.show()
      }

      if (this.elements.log.wrapper.text() !== '') {
        this.elements.buttons.copyBtn.show()
      }

      var displayNone = this.elements.summary.success.item
        .add(this.elements.summary.warning.item)
        .add(this.elements.summary.error.item)
        .add(this.elements.summary.success.item)
      displayNone.hide()
      this.elements.summary.error.item.show()
      this.updateUiShowSummaryError(header, message)
      if (hideStatusBox === true) {
        this.updateUiHideStatusBox()
      } else {
        this.updateUiStatusBoxStop()
      }
      this.updateUiStatusBoxValues()
    },

    updateUiShowSummaryError: function (header, message) {
      this.elements.summary.error.header.html(header)
      this.elements.summary.error.message.html(message)
      this.elements.summary.error.item.show()
    },

    updateUiShowSummaryWarning: function (header, message) {
      this.elements.summary.warning.header.html(header)
      this.elements.summary.warning.message.html(message)
      this.elements.summary.warning.item.show()
    },

    updateUiShowSummarySuccess: function (header) {
      this.elements.summary.success.header.html(header)
      this.elements.summary.success.item.show()
    },

    updateUiStatusBoxValues: function () {
      this.elements.statusBox.counterWarning.html(this.auditState.warningsTotal)
      this.elements.statusBox.counterCritical.html(this.auditState.criticalTotal)
      if (this.auditState.stageName !== '') {
        this.elements.statusBox.stageName.html('( ' + this.auditState.stageName + ' )')
      } else {
        this.elements.statusBox.stageName.html('')
      }
    },

    updateUiLogScroll: function () {
      this.elements.log.wrapper.scrollTop(this.elements.log.wrapper.prop('scrollHeight'));
    },

    abort: function (errorMessage) {
      this.status = this.dictionary.status.error
      this.updateLog(
        [{
          level: 'critical',
          header: this.dictionary.strings.critical,
          message: errorMessage
        }], JSON.stringify(this.wpAjax.log).toString())
      this.updateUiCriticalDuringProcess(this.dictionary.strings.criticalGenericMessage, '')
      this.status = this.dictionary.status.idle
      this.id = '';
    },

    reset: function () {
      this.updateUiReset()
    },

    registerEvents: function () {
      this.elements.buttons.startBtn.click((e) => {
        e.preventDefault()
        this.domEventStart()
      });

      this.elements.buttons.downloadBtn.click((e) => {
        e.preventDefault()

        window.location.href = this.auditState.downloadUrl

      });

      this.elements.buttons.copyBtn.click((e) => {
        e.preventDefault()

        var tableText = this.elements.log.wrapper.text();
        var $temp = $("<textarea>");
        $("body").append($temp);
        $temp.val(tableText).select();
        document.execCommand("copy");
        $temp.remove();
        alert("Skopiowano do schowka");
      });
    },

    isObjectValid(object) {

      return typeof object === 'object' && object !== null;
    },


    updateLog: function (log, code = '') {
      if (Array.isArray(log) && log.length) {
        log.forEach((logItem) => {
          this.updateStateTotal(logItem)
          this.elements.log.wrapper.append(
            this.createLogItemHtml(logItem.level, logItem.header, logItem.message, code)
          )
        });

        this.updateUiLogScroll()
      }
    },

    createLogItemHtml: function (level, header, message, code = '') {
      return `
    <td class="line log-entry">
      <span class="line-content">
        <span class="log-level log-level--${level}">${header}</span>
        ${message}<span class="code">${code}</span>
      </span>
    </td>
  `;
    },

    handleTestId: function (testId) {

    },

    handleFinish: function (response) {
      if (response.wcLogUrl !== '') {
        this.auditState.downloadUrl = response.wcLogUrl
      }
      this.updateLog(response.log)
      this.auditState.stageName = '';
      this.updateUiStatusBoxValues();
      this.updateUiFinished(response.summarySuccess, response.summaryWarning, response.summaryError)
      this.id = '';
      return false;
    },

    handleContinue: function (response) {
      this.updateLog(response.log)

      if (this.status === this.dictionary.status.requestContinue) {
        if (this.testId !== response.testId) {
          this.abort('testId not match')
          return false
        }
      } else {
        this.testId = response.testId
      }

      if (response.wcLogUrl !== '') {
        this.auditState.downloadUrl = response.wcLogUrl
      }

      this.auditState.stageName = response.stageName
      this.updateUiStatusBoxValues();

      return false;
    },

    request: function (autopayAction) {
      if (this.status === this.dictionary.status.error) {
        return false;
      }

      this.post(autopayAction, (response) => {

        this.wpAjax.log.response = JSON.stringify(response).toString()

        if (response !== 0) {

          try {

            if (response.hasOwnProperty('status')
              && (response.status === this.dictionary.responseStatus.continue
                || response.status === this.dictionary.responseStatus.finished)
            ) {
              if (response.status === this.dictionary.responseStatus.continue) {

                this.handleContinue(response)
                setTimeout(() => {

                  this.status = this.dictionary.status.requestContinue

                  this.request(this.wpAjax.autopayAction.continue)
                }, 1000)

                return false
              }

              if (response.status === this.dictionary.responseStatus.finished) {
                this.handleFinish(response)

                return false
              }

              if (response.status === 'error') {
                if (response.hasOwnProperty('message')) {
                  this.abort(response.message)
                  return false
                }

                this.abort(response.toString())
                return false
              }
            }

            this.abort(response.toString())
            return false

          } catch (e) {
            this.abort(e.message + ' ' + e.stack)
            return false;
          }

        } else {

          this.abort(response.toString())
          return false
        }

      })
    },

    domEventStart: function () {
      this.updateUiStart()
      this.request(this.wpAjax.autopayAction.new)
      this.status = this.dictionary.status.requestNew
    },

    /**
     * @param {string} attributeValue
     * @param {string} attributeType
     * @returns {jQuery | jQuery[]}
     */
    getJqueryElementByAttr: function (attributeValue, attributeType) {
      if (!['id', 'name', 'class'].includes(attributeType)) {
        throw new Error('Acceptable values are "id", "name" lub "class".');
      }

      let selector;
      switch (attributeType) {
        case 'id':
          selector = `#${attributeValue}`;
          break;
        case 'name':
          selector = `[name="${attributeValue}"]`;
          break;
        case 'class':
          selector = `.${attributeValue}`;
          break;
      }

      const elements = $(selector);

      if (elements.length === 0) {
        return attributeType === 'class' ? [] : null;
      }

      return attributeType === 'class' ? elements.toArray().map(el => $(el)) : elements;
    },


    post: function (autopay_action, callback) {
      var data = {
        action: this.wpAjax.adminAjaxActionName,
        autopay_action: autopay_action,
        nonce: this.wpAjax.nonce,
        test_id: this.testId
      };

      jQuery.ajaxSetup({
        timeout: 30000
      });

      var requestUrl = this.wpAjax.adminAjaxUrl
      this.wpAjax.log.request = {
        'url': requestUrl,
        'data': data
      }

      jQuery.post(requestUrl, data, callback).fail((jqXHR, textStatus, errorThrown) => {
        this.wpAjax.log.request.textStatus = textStatus
        this.wpAjax.log.request.errorThrown = errorThrown

        if (jqXHR.hasOwnProperty('responseText') && jqXHR.responseText !== '') {
          this.abort(jqXHR.responseText)
        } else {
          this.abort(errorThrown)
        }
        return false
      })
    },


  }


  autopayAudit.init();


});
