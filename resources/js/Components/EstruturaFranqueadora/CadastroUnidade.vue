<template>
  <div v-if="isVisible" class="sidebar-container">
    <div v-if="isLoading" class="loading-overlay">
      <div class="spinner"></div>
    </div>
    <div v-else class="painel-title">Dados da nova unidade</div>
    <div class="painel-subtitle">
      <p>Informações básicas sobre a operação</p>
    </div>
    <div class="w-full h-[525px] bg-white rounded-[20px] p-12">
      <form @submit.prevent="submitForm">
        <LabelModel text="CNPJ" />
        <InputModel v-model="cnpj" @input="applyCnpjMask" placeholder="CNPJ" />

        <LabelModel text="Cidade" />
        <InputModel v-model="cidade" placeholder="Cidade" />

        <LabelModel text="CEP" />
        <InputModel v-model="cep" @input="applyCepMask" placeholder="CEP" />

        <div class="flex space-x-4">
          <!-- flex para organizar os itens em linha, space-x-4 para espaçamento -->
          <div class="flex flex-col w-1/2">
            <!-- Flexbox dentro do div para empilhar os elementos -->
            <LabelModel text="Rua" />
            <InputModel v-model="rua" placeholder="Rua" />
          </div>
          <div class="flex flex-col w-1/2">
            <!-- Outro flex para o lado direito -->
            <LabelModel text="Número" />
            <InputModel v-model="numero" placeholder="Número" />
          </div>
        </div>
        <LabelModel text="Bairro" />
        <InputModel v-model="bairro" placeholder="Bairro" />

        <!-- <div v-if="errorMessage" class="error-message">
          {{ errorMessage }}
        </div> -->

        <!-- Componente de confirmação -->
        <ConfirmDialog
          :isVisible="isConfirmDialogVisible"
          :motivo="motivo"
          @confirm="handleConfirm"
          @cancel="handleCancel"
        />
        <div class="form-buttons">
          <ButtonCancelar text="Cancelar" @click="cancelForm" />
          <ButtonPrimaryMedio
            text="Cadastrar"
            @click="showConfirmDialog('Criar nova unidade?')"
          />
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { Inertia } from '@inertiajs/inertia';
import { defineProps, defineEmits } from 'vue';
import InputModel from '../Inputs/InputModel.vue';
import LabelModel from '../Label/LabelModel.vue';
import ButtonPrimaryMedio from '../Button/ButtonPrimaryMedio.vue';
import ButtonCancelar from '../Button/ButtonCancelar.vue';

import { useToast } from 'vue-toastification'; // Importa o hook useToast
import ConfirmDialog from '../LaytoutFranqueadora/ConfirmDialog.vue';

const toast = useToast(); // Cria a instância do toast

const props = defineProps({
  isVisible: {
    type: Boolean,
    required: true,
  },
});

const emit = defineEmits(['cancelar']);

const cnpj = ref('');
const cep = ref('');
const cidade = ref('');
const bairro = ref('');
const rua = ref('');
const numero = ref('');
const errorMessage = ref('');

const isLoading = ref(false);

const isConfirmDialogVisible = ref(false);
const motivo = ref('');

// Cancela e reseta o formulário
const cancelForm = () => {
  resetForm();
  emit('cancelar');
};

// Reseta os valores do formulário
const resetForm = () => {
  cnpj.value = '';
  cep.value = '';
  cidade.value = '';
  bairro.value = '';
  rua.value = '';
  numero.value = '';
  errorMessage.value = '';
};

// Valida os campos do formulário
const validateForm = () => {
  if (!cnpj.value || !cep.value || !bairro.value) {
    toast.error('Por favor, preencha todos os campos obrigatórios.');
    errorMessage.value = 'Por favor, preencha todos os campos obrigatórios.';
    return false;
  }
  return true;
};

// Envia os dados do formulário
const submitForm = async () => {
  if (!validateForm()) return;

  try {
    isLoading.value = true;
    const response = await axios.post('/api/unidades', {
      cnpj: cnpj.value,
      cep: cep.value,
      cidade: cidade.value,
      bairro: bairro.value,
      rua: rua.value,
      numero: numero.value,
    });

    Inertia.replace(route('franqueadora.unidades'), {
      unidades: response.data.unidades,
      preserveState: true, // Preserve o estado atual da página
    });

    toast.success('Unidade cadastrada com sucesso!');

    resetForm();
  } catch (error) {
    toast.error('Erro ao cadastrar unidade.');
    errorMessage.value =
      error.response?.data?.message || 'Erro ao cadastrar unidade.';
  } finally {
    isLoading.value = false;
  }
};

// Aplica máscara ao CNPJ
const applyCnpjMask = (event) => {
  let value = event.target.value.replace(/\D/g, '');
  if (value.length > 14) value = value.slice(0, 14);

  value = value.replace(/^(\d{2})(\d)/, '$1.$2');
  value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
  value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
  value = value.replace(/(\d{4})(\d)/, '$1-$2');

  cnpj.value = value;
};

// Aplica máscara ao CEP
const applyCepMask = (event) => {
  let value = event.target.value.replace(/\D/g, ''); // Remove caracteres não numéricos
  if (value.length > 8) value = value.slice(0, 8); // Limita o valor a 8 dígitos

  // Aplica a máscara de CEP
  value = value.replace(/(\d{5})(\d)/, '$1-$2');

  cep.value = value; // Atualiza o valor do CEP no formulário
};

const showConfirmDialog = (motivoParam) => {
  motivo.value = motivoParam; // Agora 'motivo' é reativo e você pode alterar seu valor
  isConfirmDialogVisible.value = true; // Exibe o diálogo de confirmação
};

const handleConfirm = () => {
  submitForm();
  isConfirmDialogVisible.value = false;
};

const handleCancel = () => {
  isConfirmDialogVisible.value = false;
};
</script>

<style scoped>
.painel-title {
  font-size: 34px;
  font-weight: 700;
  color: #262a27;
}

.painel-subtitle {
  font-size: 17px;
  margin-bottom: 25px;
  color: #6d6d6e;
  max-width: 600px;
}

.form-buttons {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
}

.error-message {
  color: red;
  font-size: 14px;
  margin-top: 10px;
}
.loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(255, 255, 255, 0.7);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 9999;
}

/* Estilos para o spinner */
.spinner {
  border: 4px solid #f3f3f3; /* Cor de fundo */
  border-top: 4px solid #6db631; /* Cor do topo */
  border-radius: 50%;
  width: 50px;
  height: 50px;
  animation: spin 2s linear infinite;
}

/* Animação do spinner */
@keyframes spin {
  0% {
    transform: rotate(0deg);
  }
  100% {
    transform: rotate(360deg);
  }
}
</style>
