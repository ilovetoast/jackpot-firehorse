export default function AppFooter() {
    return (
        <footer className="border-t border-gray-200 bg-white">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-4">
                <p className="text-center text-sm text-gray-500">
                    Jackpot &copy; {new Date().getFullYear()} - <a href="https://velvetysoft.com" target="_blank" rel="noopener noreferrer">Velvetysoft</a>
                </p>
            </div>
        </footer>
    )
}
