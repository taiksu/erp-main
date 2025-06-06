<template>
  <div v-if="isVisible" class="sidebar-container">
    <div class="w-full h-[680px] bg-white rounded-[20px] p-12">
      <form @submit.prevent="submitForm">
        <input type="hidden" v-model="id" />
        <LabelModel text="CNPJ" />
        <InputModel v-model="cnpj" placeholder="CNPJ" />

        <LabelModel text="Cidade" />
        <InputModel v-model="cidade" placeholder="Cidade" />

        <LabelModel text="CEP" />
        <InputModel v-model="cep" placeholder="CEP" />

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

        <div v-if="errorMessage" class="error-message">
          {{ errorMessage }}
        </div>

        <div class="form-buttons">
          <ButtonCancelar text="Cancelar" @click="cancelForm" />
          <ButtonPrimaryMedio text="Atualizar" @click="submitForm" />
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { defineProps, defineEmits } from 'vue';
import { Inertia } from '@inertiajs/inertia';

import InputModel from '../Inputs/InputModel.vue';
import LabelModel from '../Label/LabelModel.vue';
import ButtonPrimaryMedio from '../Button/ButtonPrimaryMedio.vue';
import ButtonCancelar from '../Button/ButtonCancelar.vue';

import { useToast } from 'vue-toastification'; // Importa o hook useToast

const toast = useToast(); // Cria a instância do toast

const props = defineProps({
  isVisible: {
    type: Boolean,
    required: true,
  },
  unidade: {
    type: Object,
    required: true,
  },
});

const emit = defineEmits(['cancelar']);

const id = ref('');
const cep = ref('');
const cidade = ref('');
const bairro = ref('');
const rua = ref('');
const numero = ref('');
const cnpj = ref('');
const errorMessage = ref('');

// Popula os campos com os dados da unidade ao abrir o formulário
watch(
  () => props.unidade.unidade,
  (newVal) => {
    if (newVal) {
      id.value = newVal.id || '';
      cidade.value = newVal.cidade || '';
      cep.value = newVal.cep || '';
      bairro.value = newVal.bairro || '';
      rua.value = newVal.rua || '';
      numero.value = newVal.numero || '';
      cnpj.value = newVal.cnpj || '';
    }
  },
  { immediate: true }
);

// Cancela a edição
const cancelForm = () => {
  emit('cancelar');
};

// Envia os dados atualizados para a API
const submitForm = async () => {
  if (!id.value || !cnpj.value || !cep.value || !rua.value || !numero.value) {
    errorMessage.value = 'Por favor, preencha todos os campos obrigatórios.';
    return;
  }

  try {
    await Inertia.put(route('unidades.update', props.unidade.unidade.id), {
      id: id.value,
      cep: cep.value,
      cidade: cidade.value,
      bairro: bairro.value,
      rua: rua.value,
      numero: numero.value,
      cnpj: cnpj.value,
    });

    // Sucesso: emite o evento de cancelamento para fechar o formulário
    toast.success('Unidade atualizada com sucesso!');

    emit('cancelar');
  } catch (error) {
    toast.error('Erro ao atualizar a unidade.');
    errorMessage.value =
      error.response?.data?.message || 'Erro ao atualizar a unidade.';
    console.error('Erro na atualização:', error);
  }
};
</script>

<style scoped>
.painel-title {
  font-size: 34px;
  font-weight: 700;
  color: #262a27; /* Cor escura para título */
  line-height: 30px;
}

.painel-subtitle {
  font-size: 17px;
  margin-bottom: 25px;
  color: #6d6d6e; /* Cor secundária */
  max-width: 600px; /* Limita a largura do subtítulo */
}

.form-container {
  max-width: 600px;
  margin: 0 auto;
  padding: 20px;
  background-color: white;
  border-radius: 8px;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
}

.form-buttons {
  display: flex;
  justify-content: flex-start;
  margin-top: 20px;
}

.error-message {
  color: red;
  font-size: 14px;
  margin-top: 10px;
}
</style>
