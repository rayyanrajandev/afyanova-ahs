<script setup lang="ts">
import Alert from './Alert.vue';
</script>

<template>
  <Story
    title="Feedback / Alert"
    group="feedback"
    :layout="{ type: 'grid', width: 500 }"
  >
    <Variant title="Critical">
      <Alert
        variant="critical"
        title="Critical allergy"
        description="Patient has a severe penicillin allergy."
      />
    </Variant>

    <Variant title="Warning">
      <Alert
        variant="warning"
        title="Abnormal result"
        description="Hemoglobin is below the reference range."
      />
    </Variant>

    <Variant title="Success">
      <Alert
        variant="success"
        title="Order verified"
        description="The lab order has been verified."
      />
    </Variant>

    <Variant title="Info">
      <Alert
        variant="info"
        title="Reference"
        description="This is informational content."
      />
    </Variant>

    <Variant title="With action">
      <Alert
        variant="warning"
        title="Pending review"
        description="This result requires review."
        action-label="View result"
      />
    </Variant>

    <Variant title="Dismissible">
      <Alert
        variant="info"
        title="Dismissible"
        description="This alert can be dismissed."
        dismissible
      />
    </Variant>
  </Story>
</template>