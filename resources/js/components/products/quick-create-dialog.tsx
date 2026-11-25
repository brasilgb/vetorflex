import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { parseDecimal } from '@/lib/decimal';
import { store } from '@/routes/products';

export default function QuickCreateDialog() {
    const { can } = useTenant();
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({
        type: 'simple',
        name: '',
        sale_price: '',
        initial_stock: '',
        generate_sku: true,
    });
    const { data, setData } = form;
    // O serviço de estoque devolve erros do estoque inicial no campo "quantity".
    const errors: Partial<Record<string, string>> = form.errors;
    const hasStock = data.type === 'simple' && can('products.adjust_inventory');

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((current) => ({
            ...current,
            sale_price: parseDecimal(current.sale_price),
            initial_stock: hasStock ? parseDecimal(current.initial_stock) : '',
        }));
        form.submit(store());
    }

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.resetAndClearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button type="button">Cadastro rápido</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Cadastro rápido</DialogTitle>
                <DialogDescription>
                    Informe o essencial. O cadastro pode ser completado depois.
                </DialogDescription>
                <form className="space-y-4" onSubmit={submit}>
                    <FormField id="quick-name" label="Nome" error={errors.name}>
                        <Input
                            id="quick-name"
                            required
                            maxLength={255}
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <FormField
                            id="quick-type"
                            label="Tipo"
                            error={errors.type}
                        >
                            <NativeSelect
                                id="quick-type"
                                value={data.type}
                                onChange={(event) =>
                                    setData('type', event.target.value)
                                }
                            >
                                <option value="simple">Produto</option>
                                <option value="service">Serviço</option>
                            </NativeSelect>
                        </FormField>
                        {can('products.manage_prices') && (
                            <FormField
                                id="quick-price"
                                label="Preço de venda (R$)"
                                error={errors.sale_price}
                            >
                                <Input
                                    id="quick-price"
                                    inputMode="decimal"
                                    value={data.sale_price}
                                    onChange={(event) =>
                                        setData(
                                            'sale_price',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                        {hasStock && (
                            <FormField
                                id="quick-stock"
                                label="Estoque inicial"
                                error={errors.initial_stock ?? errors.quantity}
                            >
                                <Input
                                    id="quick-stock"
                                    inputMode="decimal"
                                    value={data.initial_stock}
                                    onChange={(event) =>
                                        setData(
                                            'initial_stock',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                    </div>
                    <InputError message={errors.generate_sku} />
                    <DialogFooter>
                        <Button disabled={form.processing}>Cadastrar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
