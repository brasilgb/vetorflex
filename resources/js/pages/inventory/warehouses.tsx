import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDecimal } from '@/lib/decimal';
import { index, store, update } from '@/routes/inventory/warehouses';
import type { StockWarehouse } from '@/types';

function WarehouseDialog({ warehouse }: { warehouse: StockWarehouse | null }) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        name: warehouse?.name ?? '',
        code: warehouse?.code ?? '',
        location: warehouse?.location ?? '',
        is_active: warehouse?.is_active ?? true,
    };
    const form = useForm(initial);

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData(initial);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                {warehouse === null ? (
                    <Button>Novo depósito</Button>
                ) : (
                    <Button variant="outline" size="sm">
                        Editar
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        };

                        if (warehouse === null) {
                            form.post(store.url(), options);
                        } else {
                            form.put(update.url(warehouse.id), options);
                        }
                    }}
                >
                    <DialogTitle>
                        {warehouse === null
                            ? 'Novo depósito'
                            : `Editar ${warehouse.name}`}
                    </DialogTitle>
                    <DialogDescription>
                        Depósito, loja, prateleira ou outra localização com
                        saldo próprio.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="warehouse-name"
                            label="Nome"
                            error={form.errors.name}
                        >
                            <Input
                                id="warehouse-name"
                                required
                                maxLength={80}
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="warehouse-code"
                            label="Código"
                            error={form.errors.code}
                        >
                            <Input
                                id="warehouse-code"
                                required
                                maxLength={30}
                                value={form.data.code}
                                onChange={(event) =>
                                    form.setData(
                                        'code',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="warehouse-location"
                            label="Localização (opcional)"
                            error={form.errors.location}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="warehouse-location"
                                maxLength={160}
                                value={form.data.location}
                                onChange={(event) =>
                                    form.setData('location', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    {!warehouse?.is_default && (
                        <CheckboxField
                            id="warehouse-active"
                            label="Depósito ativo"
                            checked={form.data.is_active}
                            error={form.errors.is_active}
                            onChange={(checked) =>
                                form.setData('is_active', checked)
                            }
                        />
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>Salvar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function StockWarehouses({
    warehouses,
}: {
    warehouses: StockWarehouse[];
}) {
    const { can } = useTenant();
    const canManage = can('inventory.manage_warehouses');

    return (
        <>
            <Head title="Depósitos" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Depósitos"
                        description="O depósito principal recebe as operações sem depósito informado, inclusive de Compras, Vendas e PDV."
                    />
                    {canManage && <WarehouseDialog warehouse={null} />}
                </div>
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {warehouses.map((warehouse) => (
                        <li
                            key={warehouse.id}
                            className="space-y-2 rounded-lg border p-4"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="font-medium">
                                        {warehouse.name}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            {warehouse.code}
                                        </span>
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {warehouse.location ??
                                            'Sem localização'}
                                    </p>
                                </div>
                                {canManage && (
                                    <WarehouseDialog warehouse={warehouse} />
                                )}
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {warehouse.is_default && (
                                    <Badge>Principal</Badge>
                                )}
                                <Badge
                                    variant={
                                        warehouse.is_active
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {warehouse.is_active ? 'Ativo' : 'Inativo'}
                                </Badge>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {warehouse.items} mercadoria(s) com saldo ·{' '}
                                {formatDecimal(warehouse.quantity)} un. no total
                            </p>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

StockWarehouses.layout = {
    breadcrumbs: [inventoryBreadcrumb, { title: 'Depósitos', href: index() }],
};
