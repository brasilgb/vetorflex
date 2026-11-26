import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';

/** Campo monetário em formato brasileiro; o valor é convertido com `parseDecimal` antes do envio. */
export default function MoneyInput(props: ComponentProps<typeof Input>) {
    return (
        <Input
            inputMode="decimal"
            autoComplete="off"
            placeholder="0,00"
            {...props}
        />
    );
}
