// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

"use strict";

(() => {
  const el = (id) => document.getElementById(id);
  const label = {
    cloud: "Cloud",
    managed: "Managed",
    customer_infrastructure: "Infraestrutura do cliente",
  };
  const errorLabels = {
    unauthorized: "A credencial de analista não foi aceita.",
    economic_matrix_unavailable: "A matriz econômica não está disponível ou é inválida neste ambiente.",
    invalid_economic_input: "Confira a modalidade, a quantidade de clientes e a capacidade do catálogo.",
    invalid_input: "Os valores informados não são válidos.",
    rate_limited: "Muitas tentativas. Aguarde antes de simular novamente.",
  };
  const history = [];
  let loaded = null;
  let busy = false;

  function message(text, level = "info") {
    const target = el("feedback");
    target.textContent = text;
    target.dataset.level = level;
  }

  function setText(id, value) {
    el(id).textContent = String(value);
  }

  function money(value) {
    const source = String(value);
    if (!/^-?[0-9]+(?:\.[0-9]{1,2})?$/.test(source)) return "—";
    const negative = source.startsWith("-");
    const parts = (negative ? source.slice(1) : source).split(".");
    const integer = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    const cents = (parts[1] || "").padEnd(2, "0");
    return (negative ? "−" : "") + "R$ " + integer + "," + cents;
  }

  function decimal(value) {
    return String(value).replace(".", ",");
  }

  function candidatePrice(raw) {
    const trimmed = raw.trim();
    if (!trimmed) return null;
    if (!/^(?:0|[1-9][0-9]{0,8})(?:[.,][0-9]{1,2})?$/.test(trimmed)) {
      throw new Error("Digite o preço sem separador de milhar, com até duas casas decimais.");
    }
    return trimmed.replace(",", ".");
  }

  function setBusy(value) {
    busy = value;
    el("load-matrix").disabled = value;
    el("run-simulation").disabled = value;
    el("clear-session").disabled = value;
  }

  async function api(path, method = "GET", payload = null) {
    const token = el("analyst-token").value.trim();
    if (!token) throw new Error("Informe sua credencial de analista.");
    const headers = { Authorization: "Bearer " + token, Accept: "application/json" };
    if (payload !== null) headers["Content-Type"] = "application/json";
    let response;
    try {
      response = await fetch(path, {
        method,
        headers,
        body: payload === null ? undefined : JSON.stringify(payload),
        credentials: "omit",
        cache: "no-store",
        redirect: "error",
        referrerPolicy: "no-referrer",
      });
    } catch (_error) {
      throw new Error("Não foi possível acessar a API interna. Verifique a conexão.");
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const code = typeof data.error === "string" ? data.error : "";
      throw new Error(errorLabels[code] || "A consulta não pôde ser concluída (HTTP " + response.status + ").");
    }
    return data;
  }

  async function loadMatrix() {
    if (busy) return;
    setBusy(true);
    loaded = null;
    el("simulation-fields").disabled = true;
    el("matrix-info").hidden = true;
    el("results").hidden = true;
    try {
      message("Consultando a configuração...");
      const data = await api("/v1/economic-metadata");
      if (!data || !data.parameters || !data.parameters.version || !data.models) {
        throw new Error("A API retornou uma configuração incompleta.");
      }
      for (const id of Object.keys(label)) {
        if (typeof data.models[id] !== "string") {
          throw new Error("O catálogo não contém as três modalidades esperadas.");
        }
      }
      loaded = data.parameters;
      setText("matrix-version", loaded.version);
      setText("matrix-scope", loaded.scope === "SYNTHETIC_ONLY" ? "Exemplo fictício" : "Configuração privada");
      setText("matrix-approval", loaded.approval_status === "approved_for_quotes" ? "Aprovação declarada no catálogo" : "Em revisão");
      el("matrix-info").hidden = false;
      el("simulation-fields").disabled = false;
      message("Matriz carregada. Configure e execute o cenário.", "success");
    } catch (error) {
      message(error.message, "error");
    } finally {
      setBusy(false);
    }
  }

  function trace(result) {
    setText("trace-set", result.parameters.id);
    setText("trace-version", result.parameters.version);
    setText("trace-sha", result.parameters.sha256);
    setText("trace-fingerprint", result.simulation_fingerprint);
    setText("trace-approval", result.commercialization.quote_allowed ? "Proposta autorizada pelo catálogo" : "Apenas simulação; não autoriza comercialização");
  }

  function appendHistory(result) {
    history.push(result);
    if (history.length > 10) history.shift();
    const tbody = el("history-rows");
    tbody.replaceChildren();
    for (const item of history) {
      const tr = document.createElement("tr");
      const values = [
        label[item.input.model],
        String(item.input.customers),
        money(item.calculation.preservation_price_with_margin),
        money(item.calculation.one_time_setup_preservation_price),
      ];
      for (const value of values) {
        const td = document.createElement("td");
        td.textContent = value;
        tr.appendChild(td);
      }
      tbody.appendChild(tr);
    }
    el("history-panel").hidden = history.length === 0;
  }

  function showResult(result) {
    el("results").hidden = false;
    trace(result);
    setText("result-context", label[result.input.model] + " · " + result.input.customers + " cliente(s) · versão " + result.parameters.version);
    if (result.status === "incomplete") {
      el("calculated").hidden = true;
      el("incomplete").hidden = false;
      const list = el("missing-list");
      list.replaceChildren();
      for (const id of result.missing_parameters || []) {
        const li = document.createElement("li");
        li.textContent = id;
        list.appendChild(li);
      }
      message("A simulação foi bloqueada: existem parâmetros não preenchidos.", "error");
      return;
    }
    if (result.status !== "calculated" || !result.calculation) {
      throw new Error("A API retornou um resultado que não pôde ser interpretado.");
    }

    el("incomplete").hidden = true;
    el("calculated").hidden = false;
    const calc = result.calculation;
    setText("preservation-price", money(calc.preservation_price_with_margin));
    setText("setup-price", money(calc.one_time_setup_preservation_price));
    setText("cash-cost", money(calc.cash_cost));
    setText("full-cost", money(calc.full_cost));
    setText("preservation-cost", money(calc.preservation_cost));
    setText("setup-cost", money(calc.one_time_setup_cost));
    setText("incremental-floor", money(calc.incremental_floor));
    setText("full-price", money(calc.full_price_with_margin));
    setText("margin-target", decimal(calc.margin_target) + " (fração)");
    setText("utilization", decimal(calc.utilization_percent) + "%");
    el("candidate-analysis").hidden = calc.candidate_price === undefined;
    if (calc.candidate_price !== undefined) {
      setText("candidate-value", money(calc.candidate_price));
      setText("candidate-contribution", money(calc.candidate_incremental_contribution));
      setText("candidate-margin", money(calc.candidate_full_margin));
      setText("candidate-delta", money(calc.candidate_preservation_delta));
    }
    appendHistory(result);
    message("Cálculo concluído. Confira o identificador da matriz e a situação comercial.", "success");
  }

  async function submit(event) {
    event.preventDefault();
    if (busy) return;
    if (!loaded) {
      message("Carregue a matriz antes de simular.", "error");
      return;
    }
    const quantity = Number(el("customers").value);
    if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 1000000) {
      message("Informe uma quantidade inteira e positiva de clientes.", "error");
      return;
    }
    let candidate;
    try {
      candidate = candidatePrice(el("candidate-price").value);
    } catch (error) {
      message(error.message, "error");
      return;
    }
    const payload = {
      model: el("model").value,
      parameter_version: loaded.version,
      customers: quantity,
    };
    if (candidate !== null) payload.candidate_price = candidate;
    setBusy(true);
    try {
      message("Executando cálculo com a matriz carregada...");
      const result = await api("/v1/economic-simulations", "POST", payload);
      showResult(result);
      if (loaded.sha256 !== result.parameters.sha256) {
        message("O arquivo de parâmetros mudou desde a leitura inicial. Recarregue a matriz e confira a nova versão e o SHA-256.", "error");
      }
    } catch (error) {
      el("results").hidden = true;
      message(error.message, "error");
    } finally {
      setBusy(false);
    }
  }

  function clearSession() {
    if (busy) return;
    loaded = null;
    history.length = 0;
    el("analyst-token").value = "";
    el("candidate-price").value = "";
    el("customers").value = "1";
    el("model").value = "cloud";
    el("matrix-info").hidden = true;
    el("simulation-fields").disabled = true;
    el("results").hidden = true;
    el("history-panel").hidden = true;
    el("history-rows").replaceChildren();
    message("Sessão limpa. Informe sua credencial para carregar a matriz.");
  }

  el("load-matrix").addEventListener("click", loadMatrix);
  el("economic-form").addEventListener("submit", submit);
  el("clear-session").addEventListener("click", clearSession);
})();
