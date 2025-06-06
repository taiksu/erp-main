<template>
    <div class="relative">
      <!-- Exibição do intervalo de datas selecionadas -->
      <div
        class="flex items-center justify-between p-3 rounded-md cursor-pointer"
        @click="toggleDropdown"
      >
        <span>
          <span v-if="selectedStartDate && selectedEndDate">
            {{ selectedStartDate.format('DD/MM/YYYY') }} -
            {{ selectedEndDate.format('DD/MM/YYYY') }}
          </span>
        </span>
      </div>
  
      <!-- Dropdown do calendário -->
      <div
        v-if="isDropdownOpen"
        class="absolute z-10 bg-white border rounded-xl shadow-lg w-[300px] p-4 left-[68px] transform -translate-x-1/2"
      >
        <!-- Navegação do mês -->
        <div class="flex justify-between items-center mb-3">
          <button @click="prevMonth" class="p-2 rounded hover:bg-gray-300">
            &lt;
          </button>
          <span class="font-semibold flex items-center">
            <span>{{ currentDate.format('MMM') }}</span>
            <span class="ml-[90px]">{{ currentDate.format('YYYY') }}</span>
          </span>
          <button @click="nextMonth" class="p-2 rounded hover:bg-gray-300">
            &gt;
          </button>
        </div>
  
        <!-- Grid de dias do calendário -->
        <div class="grid grid-cols-7 gap-1 text-center text-sm">
          <div
            class="font-semibold text-gray-600"
            v-for="day in daysOfWeek"
            :key="day"
          >
            {{ day }}
          </div>
          <div
            v-for="(day, index) in daysInMonth"
            :key="index"
            @click="selectDate(day.date)"
            :class="[
              'p-2 rounded-full cursor-pointer',
              isSelected(day.date)
                ? 'bg-[#6db631] text-white'
                : isInRange(day.date)
                ? 'bg-[#b3dca6]'
                : 'hover:bg-gray-200',
            ]"
          >
            {{ day.day }}
          </div>
        </div>
  
        <!-- Categorias (Usando radio buttons) -->
        <div class="mt-5">
          <div class="flex items-center space-x-4">
            <div class="flex items-center">
              <input
                type="radio"
                id="almoco"
                value="almoco"
                v-model="selectedCategory"
                class="mr-2"
                :class="{
                  'text-[#6db631]': selectedCategory === 'almoco',
                  'border-[#6db631]': selectedCategory === 'almoco',
                }"
              />
              <label for="almoco" class="text-sm">Almoço</label>
            </div>
            <div class="flex items-center">
              <input
                type="radio"
                id="janta"
                value="janta"
                v-model="selectedCategory"
                class="mr-2"
                :class="{
                  'text-[#6db631]': selectedCategory === 'janta',
                  'border-[#6db631]': selectedCategory === 'janta',
                }"
              />
              <label for="janta" class="text-sm">Janta</label>
            </div>
          </div>
        </div>
  
        <!-- Botões do rodapé -->
        <div class="w-full mt-4 flex justify-between items-center">
          <!-- Botão Limpar -->
          <div
            class="h-10 rounded-full flex justify-center items-center px-3 py-2.5 cursor-pointer text-[#6db631] text-[15px] font-semibold font-['Figtree'] leading-tight"
          >
            Limpar
          </div>
  
          <!-- Botões Cancelar e OK -->
          <div class="flex gap-2">
            <div
              class="h-10 rounded-full flex justify-center items-center px-3 py-2.5 cursor-pointer text-[#6db631] text-[15px] font-semibold font-['Figtree'] leading-tight"
            >
              Cancelar
            </div>
            <div
              class="h-10 rounded-full flex justify-center items-center px-3 py-2.5 cursor-pointer text-[#6db631] text-[15px] font-semibold font-['Figtree'] leading-tight"
              @click="applyFilters"
            >
              OK
            </div>
          </div>
        </div>
      </div>
    </div>
  </template>
  
  <script setup>
  import { ref, computed } from 'vue';
  import dayjs from 'dayjs';
  
  // Estado para o dropdown
  const isDropdownOpen = ref(false);
  
  // Variáveis reativas para controlar as datas de início e fim
  const selectedStartDate = ref(dayjs().startOf('month'));
  const selectedEndDate = ref(dayjs().endOf('month'));
  
  const currentDate = ref(dayjs());
  
  // Controle de seleção de datas (início e fim)
  const selectingStart = ref(true); // Controla se estamos escolhendo a data de início ou fim
  
  // Dados para o calendário
  const daysOfWeek = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
  const daysInMonth = computed(() => {
    const days = [];
    const startOfMonth = selectedStartDate.value.startOf('month');
    const endOfMonth = selectedEndDate.value.endOf('month');
  
    for (let i = 0; i < startOfMonth.day(); i++) {
      days.push({ day: '', date: null }); // Espaços vazios antes do primeiro dia
    }
  
    for (let day = 1; day <= endOfMonth.date(); day++) {
      const date = startOfMonth.date(day);
      days.push({ day, date });
    }
  
    return days;
  });
  
  // Categoria selecionada
  const selectedCategory = ref('');
  
  // Funções do calendário
  const prevMonth = () => {
    currentDate.value = currentDate.value.subtract(1, 'month');
  };
  const nextMonth = () => {
    currentDate.value = currentDate.value.add(1, 'month');
  };
  
  const isSelected = (date) => {
    return (
      date &&
      (date.isSame(selectedStartDate.value) || date.isSame(selectedEndDate.value))
    );
  };
  
  // Função corrigida para verificar o intervalo
  const isInRange = (date) => {
    if (!date || !selectedStartDate.value || !selectedEndDate.value) return false;
  
    return (
      date.isAfter(selectedStartDate.value, 'day') &&
      date.isBefore(selectedEndDate.value, 'day')
    );
  };
  
  const selectDate = (date) => {
    if (!date) return;
  
    if (selectingStart.value) {
      // Se estamos selecionando o início, definimos a data de início
      selectedStartDate.value = date.startOf('day');
      selectingStart.value = false; // Agora vamos selecionar a data final
    } else {
      // Se já selecionamos o início, a data atual será o final
      selectedEndDate.value = date.endOf('day');
      selectingStart.value = true; // Reset para iniciar a seleção novamente
    }
  };
  
  // Funções para o dropdown
  const toggleDropdown = () => {
    isDropdownOpen.value = !isDropdownOpen.value;
  };
  
  // Função para aplicar os filtros
  const applyFilters = () => {
    console.log('Data inicial:', selectedStartDate.value.format('DD-MM-YYYY'));
    console.log('Data final:', selectedEndDate.value.format('DD-MM-YYYY'));
    console.log('Categoria:', selectedCategory.value);
    isDropdownOpen.value = false; // Fecha o dropdown após aplicar os filtros
  };
  </script>
  