import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import SellableUnitFields, {
    normalizeSellableUnit,
    sellableUnitData,
} from '@/components/products/sellable-unit-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { update } from '@/routes/products/variants';
import type { Option, Product, ProductVariant } from '@/types';

type Props = {
    product: Product;
    variant: ProductVariant;
    barcodeTypes: Option[];
};

function VariantForm({
    product,
    variant,
    barcodeTypes,
    onSaved,
}: Props & { onSaved: () => void }) {
    const form = useForm({
        ...sellableUnitData(variant),
        is_active: variant.is_active,
    });

    return (
        <form
            className="space-y-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform(normalizeSellableUnit);
                form.submit(
                    update({ product: product.id, variant: variant.id }),
                    { preserveScroll: true, onSuccess: onSaved },
                );
            }}
        >
            <SellableUnitFields
                data={form.data}
                errors={form.errors}
                barcodeTypes={barcodeTypes}
                isPhysical
                controlsStock={product.controls_stock}
                onChange={(changes) =>
                    form.setData((current) => ({ ...current, ...changes }))
                }
            />
            <CheckboxField
                id="variant-active"
                label="Variação ativa"
                checked={form.data.is_active}
                error={form.errors.is_active}
                onChange={(checked) => form.setData('is_active', checked)}
            />
            <DialogFooter>
                <Button disabled={form.processing}>Salvar</Button>
            </DialogFooter>
        </form>
    );
}

export default function VariantDialog(props: Props) {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    Editar
                </Button>
            </DialogTrigger>
            <DialogContent
                aria-describedby={undefined}
                className="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
            >
                <DialogTitle>{props.variant.label}</DialogTitle>
                <VariantForm {...props} onSaved={() => setIsOpen(false)} />
            </DialogContent>
        </Dialog>
    );
}
