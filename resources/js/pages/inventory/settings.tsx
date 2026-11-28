import { Head, useForm } from '@inertiajs/react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { settings as settingsRoute } from '@/routes/inventory';
import { update } from '@/routes/inventory/settings';

export default function StockSettings({
    settings,
    integrations,
}: {
    settings: { allow_negative: boolean; started_at: string | null };
    integrations: { identifier: string; name: string; enabled: boolean }[];
}) {
    const form = useForm({
        allow_negative: settings.allow_negative,
        started_at: settings.started_at ?? '',
    });

    return (
        <>
            <Head title="Configurações do estoque" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Configurações do estoque"
                    description="Regras válidas para todos os depósitos da empresa."
                />
                <form
                    className="max-w-2xl space-y-4 rounded-lg border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            started_at:
                                data.started_at === '' ? null : data.started_at,
                        }));
                        form.put(update.url(), { preserveScroll: true });
                    }}
                >
                    <CheckboxField
                        id="allow-negative"
                        label="Permitir estoque negativo (saídas acima do saldo)"
                        checked={form.data.allow_negative}
                        error={form.errors.allow_negative}
                        onChange={(checked) =>
                            form.setData('allow_negative', checked)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Desligado, nenhuma saída de Estoque, Vendas ou PDV deixa
                        o saldo negativo. Ligado, as saídas são aceitas e o
                        saldo negativo aparece nos alertas. Transferências nunca
                        deixam o depósito de origem negativo.
                    </p>
                    <FormField
                        id="started-at"
                        label="Início do controle de estoque"
                        error={form.errors.started_at}
                        hint="Registrado no primeiro cadastro ou lançamento. Movimentações anteriores não são reproduzidas automaticamente."
                    >
                        <Input
                            id="started-at"
                            type="date"
                            className="w-44"
                            value={form.data.started_at}
                            onChange={(event) =>
                                form.setData('started_at', event.target.value)
                            }
                        />
                    </FormField>
                    <Button disabled={form.processing}>Salvar</Button>
                </form>
                <section
                    aria-label="Integrações"
                    className="max-w-2xl space-y-3"
                >
                    <h3 className="font-medium">Integrações</h3>
                    <p className="text-sm text-muted-foreground">
                        Compras dá entrada no recebimento; Vendas e PDV dão
                        saída na conclusão, uma única vez; Produtos usa o mesmo
                        cadastro de mercadorias. O Financeiro não recebe
                        lançamentos de movimentações físicas.
                    </p>
                    <ul className="flex flex-wrap gap-2">
                        {integrations.map((integration) => (
                            <li key={integration.identifier}>
                                <Badge
                                    variant={
                                        integration.enabled
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {integration.name}:{' '}
                                    {integration.enabled
                                        ? 'contratado'
                                        : 'não contratado'}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

StockSettings.layout = {
    breadcrumbs: [
        inventoryBreadcrumb,
        { title: 'Configurações', href: settingsRoute() },
    ],
};
