export default function BudgetStatusBadge({ status }) {
    const getStatusConfig = (status) => {
        switch (status) {
            case 'on-track':
                return {
                    label: 'On Track',
                    className: 'bg-green-100 text-green-800',
                };
            case 'warning':
                return {
                    label: 'Warning',
                    className: 'bg-yellow-100 text-yellow-800',
                };
            case 'over':
                return {
                    label: 'Over Budget',
                    className: 'bg-red-100 text-red-800',
                };
            default:
                return {
                    label: 'Unknown',
                    className: 'bg-gray-100 text-gray-800',
                };
        }
    };

    const config = getStatusConfig(status);

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${config.className}`}>
            {config.label}
        </span>
    );
}
