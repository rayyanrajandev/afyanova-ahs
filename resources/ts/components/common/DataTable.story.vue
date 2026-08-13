<script setup lang="ts">
import DataTable, { type DataTableColumn } from './DataTable.vue';

interface PatientRow {
    id: string;
    name: string;
    mrn: string;
    age: number;
    status: string;
}

const columns: DataTableColumn<PatientRow>[] = [
    { key: 'name', label: 'Name', accessor: (r) => r.name, sticky: true },
    { key: 'mrn', label: 'MRN', accessor: (r) => r.mrn, clinical: true },
    { key: 'age', label: 'Age', accessor: (r) => r.age, align: 'right' },
    { key: 'status', label: 'Status', accessor: (r) => r.status },
];

const rows: PatientRow[] = [
    { id: '1', name: 'John Mwangi', mrn: 'MRN-1001', age: 45, status: 'Admitted' },
    { id: '2', name: 'Sarah Joseph', mrn: 'MRN-1002', age: 32, status: 'Outpatient' },
    { id: '3', name: 'Ali Hassan', mrn: 'MRN-1003', age: 58, status: 'Critical' },
    { id: '4', name: 'Grace Kimaro', mrn: 'MRN-1004', age: 27, status: 'Discharged' },
    { id: '5', name: 'Peter Mushi', mrn: 'MRN-1005', age: 61, status: 'Admitted' },
];

const manyRows: PatientRow[] = Array.from({ length: 150 }, (_, i) => ({
    id: String(i + 1),
    name: `Patient ${i + 1}`,
    mrn: `MRN-${1000 + i + 1}`,
    age: 20 + (i % 50),
    status: i % 3 === 0 ? 'Admitted' : i % 3 === 1 ? 'Outpatient' : 'Critical',
}));
</script>

<template>
  <Story
    title="Data / DataTable"
    group="data"
    :layout="{ type: 'single', iframe: 500 }"
  >
    <Variant title="Default">
      <DataTable
        :columns="columns"
        :rows="rows"
        :row-key="(r) => r.id"
      />
    </Variant>

    <Variant title="Selectable + resizable + column visibility">
      <DataTable
        :columns="columns"
        :rows="rows"
        :row-key="(r) => r.id"
        selectable
        resizable
        column-visibility
        persist-key="story-patients"
      />
    </Variant>

    <Variant title="Loading (skeleton)">
      <DataTable
        :columns="columns"
        :rows="[]"
        :row-key="(r) => r.id"
        loading
      />
    </Variant>

    <Variant title="Empty">
      <DataTable
        :columns="columns"
        :rows="[]"
        :row-key="(r) => r.id"
        empty-title="No patients found"
        empty-description="Try adjusting your search."
        empty-action-label="Register Patient"
      />
    </Variant>

    <Variant title="Error">
      <DataTable
        :columns="columns"
        :rows="[]"
        :row-key="(r) => r.id"
        error="Failed to load patients"
      />
    </Variant>

    <Variant title="Offline">
      <DataTable
        :columns="columns"
        :rows="rows"
        :row-key="(r) => r.id"
        offline
      />
    </Variant>

    <Variant title="Virtualized (150 rows)">
      <DataTable
        :columns="columns"
        :rows="manyRows"
        :row-key="(r) => r.id"
      />
    </Variant>
  </Story>
</template>