{if $jobType === 'bulk_export'}Sammelexport
{elseif $jobType === 'single_export'}Einzelexport
{elseif $jobType === 'booking' || $jobType === 'payment_booking'}Zahlungsbuchung
{elseif $jobType === 'correction'}Korrekturbeleg
{else}{$jobType|default:'Rechnungsexport'|escape:'html':'UTF-8'}{/if}
