// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

"use strict";

(() => {
  const el = (id) => document.getElementById(id);
  const labels = {
    cloud: "LibreSign Cloud",
    managed: "LibreSign Managed",
    customer_infrastructure: "Infraestrutura do cliente",
  };
  const descriptions = {
    cloud: "Hospedagem compartilhada com volume, usuários e armazenamento. É possível adicionar infraestrutura dedicada, backups e TSA.",
    managed: "Operação assistida com níveis de suporte, integrações, migração, treinamento e implantação ajustáveis.",
    customer_infrastructure: "Instalação no ambiente do contratante. A implantação é obrigatoriamente separada da manutenção mensal opcional.",
  };
  const groups = {
    capacity: ["documents", "users", "storage_gb"],
    services: ["support_hours", "recurring_support", "dedicated", "backup", "tsa", "priority_support"],
    setup: ["migration_gb", "integrations", "training_hours", "deployment_hours"],
  };
  const errors = {
    unauthorized: "Credencial de analista incorreta.",
    economic_matrix_unavailable: "A matriz econômica não está disponível neste ambiente.",
    invalid_economic_input: "Cenário inválido ou capacidade excedida. Revise as opções informadas.",
    invalid_input: "Os dados enviados à API não são válidos.",
    rate_limited: "Muitas solicitações. Tente novamente mais tarde.",
  };
  const breakdownLabels = {
    fixed_allocation: "Custo fixo rateado",
    base_variable: "Custo variável básico",
    documents: "Volume de documentos",
    users: "Usuários",
    storage_gb: "Armazenamento",
    backup: "Backup adicional",
    tsa: "Carimbo do tempo",
    dedicated: "Infraestrutura dedicada",
    full_work_cost: "Trabalho ao custo integral",
    collective_work_cost: "Trabalho ao benchmark cooperativista",
  };
  const setupLabels = {
    base: "Implantação básica",
    integrations: "Integrações",
    migration_gb: "Migração",
    training_hours: "Treinamento",
    deployment_hours: "Implantação adicional",
  };
  const history = [];
  const scenarioByModel = {};
  let activeModel = "cloud";
  let loaded = null;
  let busy = false;

  function setText(id, value) {
    el(id).textContent = String(value);
  }

  function message(text, level = "info") {
    const target = el("feedback");
    target.textContent = text;
    target.dataset.level = level;
  }

  function money(value) {
    if (value === null || value === undefined) return "Não aplicável";
    const source = String(value);
    if (!/^-?[0-9]+(?:\.[0-9]{1,2})?$/.test(source)) return "—";
    const negative = source.startsWith("-");
    const parts = (negative ? source.slice(1) : source).split(".");
    return (negative ? "−" : "") + "R$ " +
      parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".") +
      "," + (parts[1] || "").padEnd(2, "0");
  }

  function percentage(raw) {
    if (!/^(?:0|1)(?:\.[0-9]{1,8})?$/.test(String(raw))) return "—";
    const [whole, decimal = ""] = String(raw).split(".");
    if (whole === "1") return "100%";
    const shifted = decimal.padEnd(8, "0");
    const fraction = shifted.slice(2).replace(/0+$/, "");
    return String(Number(shifted.slice(0, 2))) + (fraction ? "," + fraction : "") + "%";
  }

  function price(raw) {
    const text = raw.trim();
    if (!text) return null;
    if (!/^(?:0|[1-9][0-9]{0,8})(?:[.,][0-9]{1,2})?$/.test(text)) {
      throw new Error("Informe preços sem separadores de milhar, com até duas casas decimais.");
    }
    return text.replace(",", ".");
  }

  function hours(raw, label, maximum) {
    const value = raw.trim().replace(",", ".");
    if (!/^(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?$/.test(value) ||
        Number(value) > Number(maximum)) {
      throw new Error("Confira o valor de " + label + ". Informe até duas casas decimais.");
    }
    return value;
  }

  function setBusy(value) {
    busy = value;
    for (const id of ["load-matrix", "run-simulation", "reset-scenario", "clear-session"]) {
      el(id).disabled = value;
    }
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
      throw new Error("Não foi possível acessar a API interna.");
    }
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const code = typeof body.error === "string" ? body.error : "";
      throw new Error(errors[code] || "A API retornou HTTP " + response.status + ".");
    }
    return body;
  }

  function fieldSchema(model) {
    return loaded && loaded.fields && Array.isArray(loaded.fields[model])
      ? loaded.fields[model] : [];
  }

  function readScenario() {
    const scenario = {};
    for (const field of fieldSchema(activeModel)) {
      const input = el("field-" + field.id);
      if (!input) continue;
      if (field.type === "boolean") {
        scenario[field.id] = input.checked;
      } else if (field.type === "integer") {
        const n = Number(input.value);
        if (!Number.isSafeInteger(n) || input.value.trim() === "" || n < 0 || n > field.max) {
          throw new Error("Confira " + field.label + ": informe um número inteiro válido.");
        }
        scenario[field.id] = n;
      } else if (field.type === "decimal") {
        scenario[field.id] = hours(input.value, field.label, field.max);
      }
    }
    if (scenario.backup && !scenario.storage_gb) {
      throw new Error("Configure armazenamento maior que zero para habilitar backup adicional.");
    }
    if (scenario.tsa && !scenario.documents) {
      throw new Error("Configure documentos mensais para habilitar TSA.");
    }
    return scenario;
  }

  function saveScenario() {
    if (!loaded) return;
    try {
      scenarioByModel[activeModel] = readScenario();
    } catch (_error) {
      // Editing an incomplete field must not block changing model. The server
      // still validates every completed calculation.
    }
  }

  function createField(field, value) {
    const wrapper = document.createElement("div");
    wrapper.className = field.type === "boolean" ? "field switch-field" : "field";
    const input = document.createElement("input");
    input.id = "field-" + field.id;

    if (field.type === "boolean") {
      input.type = "checkbox";
      input.checked = value === true;
      const toggle = document.createElement("label");
      toggle.className = "toggle";
      toggle.htmlFor = input.id;
      toggle.appendChild(input);
      const title = document.createElement("span");
      title.textContent = field.label;
      toggle.appendChild(title);
      wrapper.appendChild(toggle);
    } else {
      const label = document.createElement("label");
      label.htmlFor = input.id;
      label.textContent = field.label;
      wrapper.appendChild(label);
      input.type = field.type === "integer" ? "number" : "text";
      input.inputMode = field.type === "integer" ? "numeric" : "decimal";
      input.autocomplete = "off";
      input.value = String(value);
      if (field.type === "integer") {
        input.min = "0";
        input.max = String(field.max);
        input.step = "1";
      }
      wrapper.appendChild(input);
    }
    const help = document.createElement("small");
    help.textContent = field.help;
    wrapper.appendChild(help);
    if (field.id === "recurring_support") {
      input.addEventListener("change", refreshRecurringInput);
    }
    return wrapper;
  }

  function renderModel(model) {
    activeModel = model;
    setText("model-name", labels[model]);
    setText("model-description", descriptions[model]);
    const fields = fieldSchema(model);
    const preserved = scenarioByModel[model] || {};

    for (const group of Object.keys(groups)) {
      const root = el(group + "-fields");
      root.replaceChildren();
      const selected = fields.filter((field) => groups[group].includes(field.id));
      for (const field of selected) {
        const value = Object.prototype.hasOwnProperty.call(preserved, field.id)
          ? preserved[field.id] : field.default;
        root.appendChild(createField(field, value));
      }
      el(group + "-section").hidden = selected.length === 0;
    }
    refreshRecurringInput();
  }

  function refreshRecurringInput() {
    const monthly = activeModel !== "customer_infrastructure" ||
      Boolean(el("field-recurring_support") && el("field-recurring_support").checked);
    el("candidate-price").disabled = !monthly;
    el("candidate-price").parentElement.classList.toggle("muted-field", !monthly);
    setText("recurring-hint", monthly
      ? "Valor por contrato e período recorrente, sem separador de milhar."
      : "Manutenção desativada: o cálculo considera apenas a implantação.");
    if (!monthly) el("candidate-price").value = "";
  }

  async function loadMatrix() {
    if (busy) return;
    setBusy(true);
    loaded = null;
    el("simulation-fields").disabled = true;
    el("matrix-info").hidden = true;
    el("results").hidden = true;
    try {
      message("Consultando a matriz...");
      const data = await api("/v1/economic-metadata");
      if (!data || !data.parameters || !data.parameters.version ||
          !data.parameters.sha256 || !data.models || !data.scenario_fields) {
        throw new Error("A API retornou metadados incompletos.");
      }
      for (const model of Object.keys(labels)) {
        if (typeof data.models[model] !== "string" || !Array.isArray(data.scenario_fields[model])) {
          throw new Error("A matriz não declara as três modalidades com seus campos.");
        }
      }
      loaded = { ...data.parameters, fields: data.scenario_fields };
      Object.keys(scenarioByModel).forEach((key) => delete scenarioByModel[key]);
      setText("matrix-version", loaded.version);
      setText("matrix-scope", loaded.scope === "SYNTHETIC_ONLY" ? "Dados integralmente fictícios" : "Dados privados");
      setText("matrix-approval", loaded.approval_status === "approved_for_quotes"
        ? "Aprovação declarada (sujeita à autorização)" : "Em estudo · não aprovado");
      el("matrix-info").hidden = false;
      el("simulation-fields").disabled = false;
      renderModel(el("model").value);
      message("Configuração carregada. Ajuste os parâmetros e calcule.", "success");
    } catch (error) {
      message(error.message, "error");
    } finally {
      setBusy(false);
    }
  }

  function renderBreakdown(target, items, names) {
    const root = el(target);
    root.replaceChildren();
    if (!items) {
      root.appendChild(numberRow("Manutenção não contratada", "Não aplicável"));
      return;
    }
    for (const [key, title] of Object.entries(names)) {
      if (items[key] === null || items[key] === undefined) continue;
      root.appendChild(numberRow(title, money(items[key])));
    }
  }

  function numberRow(title, value) {
    const container = document.createElement("div");
    const term = document.createElement("dt");
    const description = document.createElement("dd");
    term.textContent = title;
    description.textContent = value;
    container.append(term, description);
    return container;
  }

  function trace(result) {
    setText("trace-set", result.parameters.id);
    setText("trace-version", result.parameters.version);
    setText("trace-sha", result.parameters.sha256);
    setText("trace-fingerprint", result.simulation_fingerprint);
    setText("trace-approval", result.commercialization.quote_allowed
      ? "Aprovação declarada no catálogo" : "Somente simulação; não habilita propostas");
  }

  function summary(scenario) {
    const details = [];
    for (const [key, value] of Object.entries(scenario)) {
      if (key === "recurring_support" && !value) details.push("sem mensalidade");
      else if (value === true && key !== "recurring_support") details.push(key);
      else if (value !== false && value !== 0 && Number(value) !== 0 && key !== "recurring_support") {
        details.push(key + ": " + String(value));
      }
    }
    return details.length ? details.join(" · ") : "Configuração básica";
  }

  function appendHistory(result) {
    history.push(result);
    if (history.length > 10) history.shift();
    const root = el("history-rows");
    root.replaceChildren();
    for (const item of history) {
      const tr = document.createElement("tr");
      const values = [
        labels[item.input.model],
        summary(item.input.scenario),
        String(item.input.customers),
        money(item.calculation.preservation_price_with_margin),
        money(item.calculation.one_time_setup_preservation_price),
      ];
      for (const value of values) {
        const td = document.createElement("td");
        td.textContent = value;
        tr.appendChild(td);
      }
      root.appendChild(tr);
    }
    el("history-panel").hidden = history.length === 0;
  }

  function showResult(result) {
    el("results").hidden = false;
    trace(result);
    setText("result-context", labels[result.input.model] + " · " + result.input.customers +
      " contrato(s) equivalentes · matriz " + result.parameters.version);
    if (result.status === "incomplete") {
      el("calculated").hidden = true;
      el("incomplete").hidden = false;
      const list = el("missing-list");
      list.replaceChildren();
      for (const id of result.missing_parameters || []) {
        const item = document.createElement("li");
        item.textContent = id;
        list.appendChild(item);
      }
      message("Cálculo bloqueado: existem coeficientes econômicos desconhecidos.", "error");
      return;
    }
    if (result.status !== "calculated" || !result.calculation) {
      throw new Error("Resultado inválido recebido da API.");
    }
    const calc = result.calculation;
    el("incomplete").hidden = true;
    el("calculated").hidden = false;
    setText("recurring-result-label", calc.recurring_enabled ? "Referência por período recorrente" : "Manutenção mensal não contratada");
    setText("recurring-result-help", calc.recurring_enabled
      ? "Por contrato e período, preservando o retorno cooperativista de referência."
      : "Não foi calculada mensalidade; o projeto inclui apenas a implantação.");
    setText("preservation-price", calc.recurring_enabled ? money(calc.preservation_price_with_margin) : "Não aplicável");
    setText("setup-price", money(calc.one_time_setup_preservation_price));
    setText("cash-cost", money(calc.cash_cost));
    setText("full-cost", money(calc.full_cost));
    setText("preservation-cost", money(calc.preservation_cost));
    setText("incremental-floor", money(calc.incremental_floor));
    setText("full-price", money(calc.full_price_with_margin));
    setText("tax-rate", percentage(calc.tax_rate));
    setText("default-rate", percentage(calc.default_rate));
    setText("margin-target", percentage(calc.margin_target));
    setText("utilization", String(calc.utilization_percent).replace(".", ",") + "%");
    setText("setup-hours", String(calc.setup_hours).replace(".", ",") + " h");
    renderBreakdown("breakdown-recurring", calc.recurring_enabled ? calc.cost_breakdown : null, breakdownLabels);
    renderBreakdown("breakdown-setup", calc.cost_breakdown.setup, setupLabels);

    const recurringCandidate = calc.candidate_price !== undefined;
    const setupCandidate = calc.candidate_setup_price !== undefined;
    el("candidate-analysis").hidden = !recurringCandidate && !setupCandidate;
    for (const id of ["candidate-recurring-row", "candidate-contribution-row", "candidate-margin-row", "candidate-delta-row"]) {
      el(id).hidden = !recurringCandidate;
    }
    for (const id of ["candidate-setup-row", "candidate-setup-delta-row"]) {
      el(id).hidden = !setupCandidate;
    }
    if (recurringCandidate) {
      setText("candidate-value", money(calc.candidate_price));
      setText("candidate-contribution", money(calc.candidate_incremental_contribution));
      setText("candidate-margin", money(calc.candidate_full_margin));
      setText("candidate-delta", money(calc.candidate_preservation_delta));
    }
    if (setupCandidate) {
      setText("candidate-setup-value", money(calc.candidate_setup_price));
      setText("candidate-setup-delta", money(calc.candidate_setup_delta));
    }
    appendHistory(result);
    message("Estimativa concluída. Confira as premissas e a rastreabilidade.", "success");
  }

  async function submit(event) {
    event.preventDefault();
    if (busy) return;
    if (!loaded) {
      message("Carregue a matriz antes de simular.", "error");
      return;
    }
    const customers = Number(el("customers").value);
    if (!Number.isSafeInteger(customers) || customers < 1 || customers > 1000000) {
      message("Informe um número válido de contratos para rateio.", "error");
      return;
    }
    let scenario, candidate, setupCandidate;
    try {
      scenario = readScenario();
      candidate = price(el("candidate-price").value);
      setupCandidate = price(el("candidate-setup-price").value);
    } catch (error) {
      message(error.message, "error");
      return;
    }
    scenarioByModel[activeModel] = scenario;
    const payload = {
      model: activeModel,
      parameter_version: loaded.version,
      customers,
      scenario,
    };
    if (candidate !== null) payload.candidate_price = candidate;
    if (setupCandidate !== null) payload.candidate_setup_price = setupCandidate;
    setBusy(true);
    try {
      message("Calculando configuração na matriz carregada...");
      const result = await api("/v1/economic-simulations", "POST", payload);
      showResult(result);
      if (loaded.sha256 !== result.parameters.sha256) {
        message("A matriz mudou desde o carregamento. Recarregue-a e confira a versão e o SHA-256.", "error");
      }
    } catch (error) {
      el("results").hidden = true;
      message(error.message, "error");
    } finally {
      setBusy(false);
    }
  }

  function resetScenario() {
    if (!loaded || busy) return;
    delete scenarioByModel[activeModel];
    renderModel(activeModel);
    el("candidate-price").value = "";
    el("candidate-setup-price").value = "";
    el("results").hidden = true;
    message("Configuração da modalidade restaurada. Os cenários anteriores permanecem na tabela.");
  }

  function clearSession() {
    if (busy) return;
    loaded = null;
    history.length = 0;
    Object.keys(scenarioByModel).forEach((key) => delete scenarioByModel[key]);
    el("analyst-token").value = "";
    el("candidate-price").value = "";
    el("candidate-setup-price").value = "";
    el("customers").value = "1";
    el("model").value = "cloud";
    el("matrix-info").hidden = true;
    el("simulation-fields").disabled = true;
    el("results").hidden = true;
    el("history-panel").hidden = true;
    el("history-rows").replaceChildren();
    for (const group of Object.keys(groups)) {
      el(group + "-fields").replaceChildren();
      el(group + "-section").hidden = true;
    }
    message("Sessão encerrada. Recarregue a matriz para iniciar nova validação.");
  }

  el("model").addEventListener("change", () => {
    if (!loaded) return;
    saveScenario();
    renderModel(el("model").value);
    el("results").hidden = true;
  });
  el("load-matrix").addEventListener("click", loadMatrix);
  el("economic-form").addEventListener("submit", submit);
  el("reset-scenario").addEventListener("click", resetScenario);
  el("clear-session").addEventListener("click", clearSession);
})();
