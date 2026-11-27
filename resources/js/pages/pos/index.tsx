import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import MoneyInput from '@/components/finance/money-input';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import NativeTextarea from '@/components/native-textarea';
import { posBreadcrumb } from '@/components/pos/pos-nav';
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
import { useTenant } from '@/hooks/use-tenant';
import { parseDecimal } from '@/lib/decimal';
import { uuid } from '@/lib/uuid';
import { register } from '@/routes/pos';
import { index as sales } from '@/routes/pos/sales';
import { open, show as sessionShow } from '@/routes/pos/sessions';
import { index as terminalsIndex } from '@/routes/pos/terminals';
import type { PosSessionData, PosTerminal } from '@/types';

function OpenSessionDialog({ terminal }: { terminal: PosTerminal }) {
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({
        opening_amount: '',
        opening_notes: '',
        idempotency_key: uuid(),
    });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData({
                    opening_amount: '',
                    opening_notes: '',
                    idempotency_key: uuid(),
                });
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button>Abrir caixa</Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            opening_amount:
                                parseDecimal(data.opening_amount) || '0',
                        }));
                        form.post(open.url(terminal.id));
                    }}
                >
                    <DialogTitle>Abrir caixa — {terminal.name}</DialogTitle>
                    <DialogDescription>
                        Informe o dinheiro de troco na gaveta. O saldo inicial
                        não é receita.
                    </DialogDescription>
                    <FormField
                        id="opening-amount"
                        label="Valor inicial em dinheiro"
                        error={
                            form.errors.opening_amount ??
                            (form.errors as Record<string, string>)
                                .pos_terminal_id
                        }
                    >
                        <MoneyInput
                            id="opening-amount"
                            autoFocus
                            value={form.data.opening_amount}
                            onChange={(event) =>
                                form.setData(
                                    'opening_amount',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        id="opening-notes"
                        label="Observações"
                        error={form.errors.opening_notes}
                    >
                        <NativeTextarea
                            id="opening-notes"
                            maxLength={1000}
                            value={form.data.opening_notes}
                            onChange={(event) =>
                                form.setData(
                                    'opening_notes',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>Abrir caixa</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function PosHome({
    terminals,
    mySessions,
    suspendedCount,
    canOpen,
    canManageTerminals,
}: {
    terminals: (PosTerminal & { may_open: boolean })[];
    mySessions: PosSessionData[];
    suspendedCount: number;
    canOpen: boolean;
    canManageTerminals: boolean;
}) {
    const { can, formatDateTime } = useTenant();

    return (
        <>
            <Head title="PDV" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="PDV — Frente de Caixa"
                        description={`Abra o caixa de um terminal para vender. ${suspendedCount > 0 ? `${suspendedCount} venda(s) suspensa(s) aguardando.` : ''}`}
                    />
                    <div className="flex flex-wrap gap-2">
                        {can('pos.view_sessions') && (
                            <Button variant="outline" asChild>
                                <Link href={sales()}>Vendas do caixa</Link>
                            </Button>
                        )}
                        {canManageTerminals && (
                            <Button variant="outline" asChild>
                                <Link href={terminalsIndex()}>Terminais</Link>
                            </Button>
                        )}
                    </div>
                </div>
                {mySessions.length > 0 && (
                    <section
                        className="space-y-2"
                        aria-label="Meus caixas abertos"
                    >
                        <h3 className="font-medium">Meus caixas abertos</h3>
                        <ul className="grid gap-3 md:grid-cols-2">
                            {mySessions.map((session) => (
                                <li
                                    key={session.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary/40 p-4"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {session.terminal.name}
                                        </span>{' '}
                                        · sessão {session.number}
                                        <span className="block text-xs text-muted-foreground">
                                            Aberto em{' '}
                                            {formatDateTime(session.opened_at)}
                                        </span>
                                    </span>
                                    <span className="flex gap-2">
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={sessionShow(session.id)}
                                            >
                                                Resumo
                                            </Link>
                                        </Button>
                                        <Button asChild>
                                            <Link href={register(session.id)}>
                                                Ir para o caixa
                                            </Link>
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {terminals.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Nenhum terminal ativo.{' '}
                        {canManageTerminals
                            ? 'Cadastre o primeiro caixa em Terminais.'
                            : 'Peça ao responsável para cadastrar um terminal.'}
                    </p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {terminals.map((terminal) => (
                            <li
                                key={terminal.id}
                                className="space-y-3 rounded-lg border p-4"
                            >
                                <div>
                                    <p className="font-medium">
                                        {terminal.name}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            {terminal.code}
                                        </span>
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {terminal.location ?? 'Sem localização'}
                                    </p>
                                </div>
                                {terminal.open_session ? (
                                    <Badge variant="secondary">
                                        Aberto · sessão{' '}
                                        {terminal.open_session.number} ·{' '}
                                        {terminal.open_session.user_name}
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">Fechado</Badge>
                                )}
                                <div>
                                    {!terminal.open_session &&
                                        canOpen &&
                                        terminal.may_open && (
                                            <OpenSessionDialog
                                                terminal={terminal}
                                            />
                                        )}
                                    {!terminal.open_session &&
                                        canOpen &&
                                        !terminal.may_open && (
                                            <p className="text-xs text-muted-foreground">
                                                Você não é operador deste
                                                terminal.
                                            </p>
                                        )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

PosHome.layout = { breadcrumbs: [posBreadcrumb] };
