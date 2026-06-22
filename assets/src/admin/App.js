import { Dashboard } from './views/Dashboard';
import { Titles } from './views/Titles';
import { Social } from './views/Social';
import { Sitemaps } from './views/Sitemaps';
import { Schema } from './views/Schema';
import { Ai } from './views/Ai';
import { Redirects } from './views/Redirects';
import { NotFound } from './views/NotFound';

const VIEWS = {
	dashboard: Dashboard,
	titles: Titles,
	social: Social,
	sitemaps: Sitemaps,
	schema: Schema,
	redirects: Redirects,
	notfound: NotFound,
	ai: Ai,
};

export function App( { view } ) {
	const View = VIEWS[ view ] ?? Dashboard;
	return <View />;
}
